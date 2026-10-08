<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Invoice;
use App\Models\JobImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Full-size camera photos are accepted and shrunk server-side; customer sign-off
 * on a visit, optionally required by the company before completing.
 */
class VisitUploadSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function startVisit(): array
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);
        $this->actingAsUser($tech);
        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');

        return [$tenant, $job, $visitId];
    }

    /** A poorly-compressible JPEG, like a real camera photo. */
    private function cameraPhoto(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, mt_rand(0, 0xFFFFFF));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'cam').'.jpg';
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return new UploadedFile($path, 'IMG_20261008_101500.jpg', 'image/jpeg', null, true);
    }

    private function signaturePng(): UploadedFile
    {
        return UploadedFile::fake()->image('signature.png', 600, 240);
    }

    public function test_full_size_camera_photo_is_accepted_and_downscaled(): void
    {
        Storage::fake('private');
        [$tenant, , $visitId] = $this->startVisit();

        $photo = $this->cameraPhoto(4000, 3000);
        $this->assertGreaterThan(8 * 1024 * 1024, $photo->getSize());

        $id = $this->postJson("/api/v1/visits/{$visitId}/images", ['type' => 'serial', 'image' => $photo])
            ->assertCreated()->json('data.id');

        $stored = $this->inTenant($tenant, fn () => JobImage::findOrFail($id));
        [$w, $h] = getimagesizefromstring(Storage::disk('private')->get($stored->file_path));
        $this->assertSame([2000, 1500], [$w, $h]);
        $this->assertLessThan(3 * 1024 * 1024, $stored->size);
    }

    public function test_small_photo_is_stored_untouched(): void
    {
        Storage::fake('private');
        [$tenant, , $visitId] = $this->startVisit();

        $id = $this->postJson("/api/v1/visits/{$visitId}/images", ['type' => 'bill', 'image' => UploadedFile::fake()->image('bill.png', 800, 600)])
            ->assertCreated()->json('data.id');
        $stored = $this->inTenant($tenant, fn () => JobImage::findOrFail($id));
        $this->assertStringEndsWith('.png', $stored->file_path);
    }

    public function test_signature_is_saved_replaced_and_shown_on_invoice(): void
    {
        Storage::fake('private');
        [$tenant, $job, $visitId] = $this->startVisit();

        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => $this->signaturePng()])
            ->assertStatus(422)->assertJsonValidationErrors('signer_name');
        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => UploadedFile::fake()->image('s.jpg', 600, 240), 'signer_name' => 'Anil'])
            ->assertStatus(422)->assertJsonValidationErrors('signature');

        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => $this->signaturePng(), 'signer_name' => 'Anil'])
            ->assertOk()->assertJsonPath('data.signer_name', 'Anil');
        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => $this->signaturePng(), 'signer_name' => ' Anil Kumar '])
            ->assertOk()->assertJsonPath('data.signer_name', 'Anil Kumar')->assertJsonCount(1, 'data.images');

        $signatures = $this->inTenant($tenant, fn () => JobImage::where('type', 'signature')->get());
        $this->assertCount(1, $signatures);
        $this->assertCount(1, Storage::disk('private')->allFiles());

        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $this->postJson("/api/v1/visits/{$visitId}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done', 'labour_charge' => 50000, 'payment_method' => 'credit',
        ])->assertOk();

        // Closed visits cannot be re-signed.
        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => $this->signaturePng(), 'signer_name' => 'X'])->assertStatus(422);

        $invoice = $this->inTenant($tenant, fn () => Invoice::where('job_id', $job->id)->firstOrFail());
        $admin = $this->makeUser($tenant, Role::Admin);
        $this->actingAsUser($admin);
        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_company_can_require_signature_before_completion(): void
    {
        Storage::fake('private');
        [$tenant, , $visitId] = $this->startVisit();
        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), ['jobs' => ['require_signature' => true]])]);
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $payload = ['status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done'];

        $this->postJson("/api/v1/visits/{$visitId}/complete", $payload)->assertStatus(422)->assertJsonValidationErrors('signature');
        // Pending visits do not need a signature.
        $this->postJson("/api/v1/visits/{$visitId}/signature", ['signature' => $this->signaturePng(), 'signer_name' => 'Anil'])->assertOk();
        $this->postJson("/api/v1/visits/{$visitId}/complete", $payload)->assertOk();
    }

    public function test_admin_can_toggle_require_signature_setting(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $this->actingAsUser($this->makeUser($tenant, Role::Admin));
        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['require_signature' => true]])
            ->assertOk()->assertJsonPath('data.jobs.require_signature', true);
    }
}
