<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Estimates: made by the office, sent to the customer, then approved or rejected. */
class EstimateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $part;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->part = $this->makeItem($this->tenant, 5); // ₹500 each
    }

    private function payload(int $customerId, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $customerId,
            'subject' => 'Compressor replacement',
            'items' => [
                ['type' => 'service', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 150000],
                ['type' => 'part', 'item_id' => $this->part->id, 'quantity' => 2],
                ['type' => 'part', 'description' => 'Copper pipe (to be bought)', 'quantity' => 3, 'unit_price' => 20000],
            ],
            'discount' => 10000,
        ], $extra);
    }

    public function test_an_estimate_is_totalled_numbered_and_never_touches_stock(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $job = $this->makeJob($this->tenant, $customer);
        $this->actingAsUser($this->admin);

        $estimate = $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['job_id' => $job->id]))->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_service_charge', 150000)
            ->assertJsonPath('data.total_spare_charge', 160000)
            ->assertJsonPath('data.total_amount', 300000)
            ->assertJsonPath('data.job.crm_call_id', $job->crm_call_id)
            ->assertJsonCount(3, 'data.items')
            ->json('data');
        $this->assertMatchesRegularExpression('/^EST-\d{4}-00001$/', $estimate['estimate_number']);
        $this->assertNotEmpty($estimate['valid_until']);
        $this->assertStringContainsString('/api/v1/estimate/', $estimate['share_url']);
        $this->assertEquals(5.0, $this->inTenant($this->tenant, fn () => InventoryStock::where('item_id', $this->part->id)->sum('quantity_available')));

        // Validation: lines are needed, a service needs its charge, and the job must be this customer's.
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'items' => []])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'items' => [['type' => 'service', 'description' => 'x', 'quantity' => 1]]])->assertStatus(422);
        $other = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant));
        $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['job_id' => $other->id]))->assertStatus(422)->assertJsonValidationErrors('job_id');
        $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['discount' => 99999999]))->assertStatus(422)->assertJsonValidationErrors('discount');

        // Lists, filters and the PDF.
        $this->getJson('/api/v1/estimates?search=compressor')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/estimates?job_id={$job->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/estimates/summary')->assertJsonPath('data.draft', 1)->assertJsonPath('data.open_total', 300000);
        $this->get("/api/v1/estimates/{$estimate['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_estimate_number_can_be_typed_and_edited_but_never_repeated(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $this->actingAsUser($this->admin);

        // Typed, it is used as it is; left blank, the next automatic number is given.
        $typed = $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['estimate_number' => 'QT/2026-27/014']))->assertCreated()
            ->assertJsonPath('data.estimate_number', 'QT/2026-27/014')->json('data');
        $auto = $this->postJson('/api/v1/estimates', $this->payload($customer->id))->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^EST-\d{4}-00001$/', $auto['estimate_number']);

        // An automatic number skips one a person already typed.
        $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['estimate_number' => preg_replace('/00001$/', '00002', $auto['estimate_number'])]))->assertCreated();
        $next = $this->postJson('/api/v1/estimates', $this->payload($customer->id))->assertCreated()->json('data.estimate_number');
        $this->assertMatchesRegularExpression('/00003$/', $next);

        // The same number twice, or with spaces, is refused with a plain message.
        $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['estimate_number' => 'QT/2026-27/014']))->assertStatus(422)->assertJsonValidationErrors('estimate_number');
        $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['estimate_number' => 'QT 014']))->assertStatus(422)->assertJsonValidationErrors('estimate_number');

        // Edited while open; keeping its own number is fine, a blank one keeps it, another's number is refused.
        $this->patchJson("/api/v1/estimates/{$typed['id']}", ['estimate_number' => 'QT-15'])->assertOk()->assertJsonPath('data.estimate_number', 'QT-15');
        $this->patchJson("/api/v1/estimates/{$typed['id']}", ['estimate_number' => 'QT-15', 'subject' => 'Same number'])->assertOk();
        $this->patchJson("/api/v1/estimates/{$typed['id']}", ['estimate_number' => ''])->assertOk()->assertJsonPath('data.estimate_number', 'QT-15');
        $this->patchJson("/api/v1/estimates/{$typed['id']}", ['estimate_number' => $auto['estimate_number']])->assertStatus(422)->assertJsonValidationErrors('estimate_number');

        // A decided estimate keeps its number until it is reopened; a copy is numbered afresh.
        $this->postJson("/api/v1/estimates/{$typed['id']}/status", ['status' => 'approved'])->assertOk();
        $this->patchJson("/api/v1/estimates/{$typed['id']}", ['estimate_number' => 'QT-16'])->assertStatus(422);
        $copy = $this->postJson("/api/v1/estimates/{$typed['id']}/duplicate")->assertCreated()->json('data.estimate_number');
        $this->assertNotSame('QT-15', $copy);
    }

    public function test_it_is_sent_then_decided_and_locked_until_reopened(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $this->actingAsUser($this->admin);
        $id = $this->postJson('/api/v1/estimates', $this->payload($customer->id))->json('data.id');

        // Edited while still open: the lines are replaced and the totals follow.
        $this->patchJson("/api/v1/estimates/{$id}", ['items' => [['type' => 'service', 'description' => 'Labour', 'quantity' => 2, 'unit_price' => 100000]], 'discount' => 0])
            ->assertOk()->assertJsonPath('data.total_amount', 200000)->assertJsonCount(1, 'data.items');

        $this->postJson("/api/v1/estimates/{$id}/status", ['status' => 'sent'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/estimates/{$id}/status", ['status' => 'approved', 'note' => 'Approved on the phone'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decider.id', $this->admin->id)->assertJsonPath('data.decision_note', 'Approved on the phone');

        // Decided: no edits, no delete, no second decision.
        $this->patchJson("/api/v1/estimates/{$id}", ['subject' => 'Changed'])->assertStatus(422);
        $this->deleteJson("/api/v1/estimates/{$id}")->assertStatus(422);
        $this->postJson("/api/v1/estimates/{$id}/status", ['status' => 'rejected'])->assertStatus(422);
        $this->getJson('/api/v1/estimates/summary')->assertJsonPath('data.approved', 1)->assertJsonPath('data.approved_total', 200000);

        // Reopened, it can be changed, rejected and then deleted.
        $this->postJson("/api/v1/estimates/{$id}/status", ['status' => 'draft'])->assertOk()->assertJsonPath('data.decided_at', null);
        $this->patchJson("/api/v1/estimates/{$id}", ['subject' => 'Changed'])->assertOk()->assertJsonPath('data.subject', 'Changed')->assertJsonPath('data.total_amount', 200000);
        $copy = $this->postJson("/api/v1/estimates/{$id}/duplicate")->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', 200000)->json('data');
        $this->assertNotSame($id, $copy['id']);
        $this->postJson("/api/v1/estimates/{$id}/status", ['status' => 'rejected', 'note' => 'Too costly'])->assertOk();
        $this->deleteJson("/api/v1/estimates/{$id}")->assertOk();
        $this->assertDatabaseMissing('estimates', ['id' => $id]);
        $this->assertDatabaseMissing('estimate_items', ['estimate_id' => $id]);
    }

    public function test_an_open_estimate_past_its_last_day_shows_as_expired(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $this->actingAsUser($this->admin);
        $old = $this->postJson('/api/v1/estimates', $this->payload($customer->id, ['estimate_date' => now()->subDays(40)->toDateString(), 'valid_until' => now()->subDays(25)->toDateString()]))
            ->assertCreated()->assertJsonPath('data.is_expired', true)->json('data.id');
        $this->postJson('/api/v1/estimates', $this->payload($customer->id))->assertCreated()->assertJsonPath('data.is_expired', false);

        $this->assertSame([$old], array_column($this->getJson('/api/v1/estimates?status=expired')->json('data'), 'id'));
        $this->getJson('/api/v1/estimates?status=open')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/estimates/summary')->assertJsonPath('data.expired', 1)->assertJsonPath('data.draft', 1);
    }

    public function test_gst_is_split_out_only_on_a_gst_estimate(): void
    {
        $this->tenant->update(['gstin' => '29ABCDE1234F1Z5', 'state' => 'Karnataka']);
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Karnataka']);
        $this->actingAsUser($this->admin);

        $gst = $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'is_gst' => true, 'prices_include_gst' => true, 'items' => [['type' => 'service', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 118000]]])
            ->assertCreated()->json('data');
        $this->assertSame(118000, $gst['total_amount']);
        $this->assertSame(100000, $gst['taxable_amount']);
        $this->assertSame(9000, $gst['cgst_amount']);
        $this->assertSame(9000, $gst['sgst_amount']);

        $this->patchJson("/api/v1/estimates/{$gst['id']}", ['is_gst' => false])->assertOk()
            ->assertJsonPath('data.total_amount', 118000)->assertJsonPath('data.taxable_amount', 118000)->assertJsonPath('data.cgst_amount', 0);
    }

    public function test_who_may_use_estimates_and_the_shared_link(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $id = $this->actingAsUser($this->admin)->postJson('/api/v1/estimates', $this->payload($customer->id))->json('data.id');
        $url = $this->getJson("/api/v1/estimates/{$id}")->json('data.share_url');

        // Technicians and the store keeper have no estimates; a coordinator does.
        $this->forgetGuards()->actingAsUser($this->makeUser($this->tenant, Role::Technician))->getJson('/api/v1/estimates')->assertForbidden();
        $this->forgetGuards()->actingAsUser($this->makeUser($this->tenant, Role::Coordinator))->getJson('/api/v1/estimates')->assertOk()->assertJsonCount(1, 'data');

        // Another company never sees it.
        ['admin' => $otherAdmin] = $this->makeTenant('other');
        $this->forgetGuards()->actingAsUser($otherAdmin)->getJson("/api/v1/estimates/{$id}")->assertNotFound();

        // The customer's link works without signing in; a wrong token does not.
        $this->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/estimate/'.str_repeat('x', 48).'/pdf')->assertNotFound();
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Estimate::count()));
    }
}
