<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\CustomerProduct;
use App\Models\Review;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Completed jobs wait in the technician's app for the customer's feedback; the Jobs list's warranty filter;
 * the photos ZIP; and what the technician app is told to show.
 */
class FeedbackWarrantyPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tech = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
    }

    private function techJobIds(): array
    {
        return collect($this->forgetGuards()->actingAsUser($this->tech)->getJson('/api/v1/jobs')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_a_completed_job_stays_with_the_technician_until_the_office_collects_feedback(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $waiting = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(10)]);
        $coded = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(10), 'customer_satisfaction_code' => 'HAPPY1']);
        $cancelled = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'cancelled', 'cancelled_at' => now()->subDays(10)]);

        // Having a satisfaction code does NOT mark feedback as collected — the office does that.
        $this->assertNull($coded->fresh()->feedback_taken_at);

        // Both completed jobs stay in the technician's list until the office collects feedback.
        $ids = $this->techJobIds();
        $this->assertContains($waiting->id, $ids);
        $this->assertContains($coded->id, $ids);
        $this->getJson("/api/v1/jobs/{$waiting->id}")->assertJsonPath('data.feedback_pending', true);

        // The technician cannot mark feedback — only the office can.
        $this->postJson("/api/v1/jobs/{$waiting->id}/feedback", ['note' => 'x'])->assertForbidden();

        // The office collects feedback for both.
        $this->forgetGuards()->actingAsUser($this->admin);
        $this->postJson("/api/v1/jobs/{$waiting->id}/feedback", ['note' => 'Customer happy'])->assertOk()
            ->assertJsonPath('data.feedback_pending', false);
        $this->postJson("/api/v1/jobs/{$coded->id}/feedback", ['note' => 'Verified code'])->assertOk()
            ->assertJsonPath('data.feedback_pending', false);

        // Now both hide from the technician's app.
        $this->assertSame([], $this->techJobIds());

        // The office still has all three, none pending.
        $this->forgetGuards()->actingAsUser($this->admin);
        $this->assertCount(3, $this->getJson('/api/v1/jobs')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/jobs?quick=feedback_pending')->json('data'));
    }

    public function test_the_office_marks_feedback_without_a_code_and_a_portal_rating_counts_too(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $a = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(5)]);
        $b = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(5)]);
        $open = $this->makeJob($this->tenant, $customer, $this->tech);

        $this->actingAsUser($this->admin);
        $this->getJson('/api/v1/jobs/quick-counts')->assertJsonPath('data.feedback_pending', 2);
        $this->postJson("/api/v1/jobs/{$open->id}/feedback", ['note' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/jobs/{$a->id}/feedback", ['note' => 'Called the customer, happy'])->assertOk()
            ->assertJsonPath('data.feedback_pending', false)->assertJsonPath('data.feedback_taker.id', $this->admin->id);

        $this->inTenant($this->tenant, fn () => Review::create(['job_id' => $b->id, 'customer_id' => $customer->id, 'technician_id' => $this->tech->id, 'rating' => 5]));
        $this->assertNotNull($b->fresh()->feedback_taken_at);
        $this->assertSame([$open->id], $this->techJobIds());
    }

    public function test_the_pending_filter_lists_every_completed_job_nobody_has_recorded_feedback_for(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $done = fn (array $extra = []) => $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(20)] + $extra);

        // Completed before feedback was tracked: the migration stamps it as taken so it leaves the technician's app, but nobody recorded anything.
        $old = $done();
        DB::table('service_jobs')->where('id', $old->id)->update(['feedback_taken_at' => now()->subDays(10)]);
        // A code the technician typed on site is not collected feedback.
        $codeOnly = $done(['customer_satisfaction_code' => 'HAPPY1']);
        $marked = $done();
        $rated = $done();
        $open = $this->makeJob($this->tenant, $customer, $this->tech);

        $this->actingAsUser($this->admin);
        $this->postJson("/api/v1/jobs/{$marked->id}/feedback", ['note' => 'Called the customer, happy'])->assertOk();
        $this->inTenant($this->tenant, fn () => Review::create(['job_id' => $rated->id, 'customer_id' => $customer->id, 'technician_id' => $this->tech->id, 'rating' => 4]));

        $pending = collect($this->getJson('/api/v1/jobs?quick=feedback_pending')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$old->id, $codeOnly->id])->sort()->values()->all(), $pending);
        $this->assertNotContains($open->id, $pending);
        $this->getJson('/api/v1/jobs/quick-counts')->assertJsonPath('data.feedback_pending', 2);

        // The list carries who marked it and the portal rating, so its badge agrees with the filter.
        $rows = collect($this->getJson('/api/v1/jobs')->json('data'))->keyBy('id');
        $this->assertSame($this->admin->id, $rows[$marked->id]['feedback_taken_by']);
        $this->assertNotNull($rows[$rated->id]['review']);
        $this->assertNull($rows[$old->id]['feedback_taken_by']);

        // Marking an old job collected takes it off the filter.
        $this->postJson("/api/v1/jobs/{$old->id}/feedback", [])->assertOk();
        $this->getJson('/api/v1/jobs/quick-counts')->assertJsonPath('data.feedback_pending', 1);
    }

    public function test_the_wait_for_feedback_can_be_switched_off(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(10)]);
        $this->assertSame([$job->id], $this->techJobIds());

        $this->forgetGuards()->actingAsUser($this->admin)->putJson('/api/v1/settings/preferences', ['jobs' => ['tech_keep_until_feedback' => false]])->assertOk();
        $this->assertSame([], $this->techJobIds());
    }

    public function test_completing_a_visit_with_a_code_does_not_mark_feedback_as_collected(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $action = $this->inTenant($this->tenant, fn () => ActionTakenOption::first());
        $this->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Gas refilled', 'labour_charge' => 0, 'payment_method' => 'cash',
            'customer_satisfaction_code' => 'HC-991',
        ])->assertOk();

        // The code is saved, but feedback is still pending — the office has to collect it.
        $fresh = $job->fresh();
        $this->assertSame('HC-991', $fresh->customer_satisfaction_code);
        $this->assertTrue($fresh->feedback_pending);
        $this->assertNull($fresh->feedback_taken_at);
    }

    public function test_jobs_filter_by_warranty_and_carry_the_cover(): void
    {
        $unit = function (array $warranty) {
            $customer = $this->makeCustomer($this->tenant);
            $product = $this->inTenant($this->tenant, fn () => tap(CustomerProduct::where('customer_id', $customer->id)->first())->update($warranty));

            return $this->makeJob($this->tenant, $customer, null, ['customer_product_id' => $product->id]);
        };
        $dated = $unit(['warranty_type' => 'in_warranty', 'warranty_expiry' => now()->addMonths(3)->toDateString()]);
        $noDate = $unit(['warranty_type' => 'amc']);
        $expired = $unit(['warranty_type' => 'in_warranty', 'warranty_expiry' => now()->subDay()->toDateString()]);
        $out = $unit(['warranty_type' => 'out_of_warranty']);
        $unknown = $unit([]);

        $this->actingAsUser($this->admin);
        $ids = fn (string $query) => collect($this->getJson("/api/v1/jobs?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$dated->id, $noDate->id], $ids('warranty=under'));
        $this->assertSame([$expired->id, $out->id], $ids('warranty=out'));
        $this->assertCount(5, $ids(''));
        $this->assertNotContains($unknown->id, [...$ids('warranty=under'), ...$ids('warranty=out')]);

        $rows = collect($this->getJson('/api/v1/jobs')->json('data'))->keyBy('id');
        $this->assertTrue($rows[$dated->id]['customer_product']['under_warranty']);
        $this->assertTrue($rows[$noDate->id]['customer_product']['under_warranty']);
        $this->assertFalse($rows[$expired->id]['customer_product']['under_warranty']);
        $this->assertFalse($rows[$out->id]['customer_product']['under_warranty']);
    }

    public function test_job_photos_download_as_one_zip_for_the_office_and_the_assigned_technician(): void
    {
        Storage::fake('private');
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $this->actingAsUser($this->tech);
        $this->getJson("/api/v1/jobs/{$job->id}/photos.zip")->assertNotFound();

        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/images", ['type' => 'bill', 'image' => UploadedFile::fake()->image('bill.jpg', 400, 300)])->assertCreated();
        $this->postJson("/api/v1/visits/{$visit}/images", ['type' => 'serial', 'image' => UploadedFile::fake()->image('serial.jpg', 400, 300)])->assertCreated();

        $response = $this->get("/api/v1/jobs/{$job->id}/photos.zip")->assertOk();
        $this->assertStringContainsString('.zip', (string) $response->headers->get('content-disposition'));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('Bill/Bill-01.jpg', $zip->getNameIndex(0));
        $zip->close();

        // Another technician only gets it once the job is theirs.
        $other = $this->makeUser($this->tenant, Role::Technician);
        $this->forgetGuards()->actingAsUser($other)->get("/api/v1/jobs/{$job->id}/photos.zip")->assertNotFound();
        $this->inTenant($this->tenant, fn () => ServiceJob::whereKey($job->id)->update(['assigned_technician_id' => $other->id]));
        $this->get("/api/v1/jobs/{$job->id}/photos.zip")->assertOk();
        $this->forgetGuards()->actingAsUser($this->admin)->get("/api/v1/jobs/{$job->id}/photos.zip")->assertOk();
    }

    public function test_leave_in_the_technician_app_is_off_until_the_company_turns_it_on(): void
    {
        $this->actingAsUser($this->tech)->getJson('/api/v1/master/lookups')->assertOk()->assertJsonPath('data.tech_app.show_leave', false);
        $this->forgetGuards()->actingAsUser($this->admin)->putJson('/api/v1/settings/preferences', ['tech_app' => ['show_leave' => true]])->assertOk();
        $this->forgetGuards()->actingAsUser($this->tech)->getJson('/api/v1/master/lookups')->assertJsonPath('data.tech_app.show_leave', true);
    }
}
