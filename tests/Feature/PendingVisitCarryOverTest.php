<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\JobVisit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A job left pending keeps its photos, voice notes and service summary for the next visit, whoever takes it.
 */
class PendingVisitCarryOverTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Tenant $tenant;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->ctx = $this->makeTenant();
        $this->tenant = $this->ctx['tenant'];
        $this->tech = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->fresh()->mergedSettings(), ['jobs' => ['resume_needs_approval' => false]])]);
    }

    /** A job whose first visit, by the first technician, has a serial photo, a voice note and a summary, and is left pending. */
    private function leftPendingWithUploads(): int
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/images", ['type' => 'serial', 'image' => UploadedFile::fake()->image('serial.jpg', 400, 300)])->assertCreated();
        $this->postJson("/api/v1/visits/{$visit}/voice-notes", ['audio' => UploadedFile::fake()->create('note.m4a', 10, 'audio/mp4'), 'duration' => 5])->assertCreated();
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Compressor noisy, needs a part check'])->assertOk();

        return $job->id;
    }

    public function test_another_technician_gets_the_earlier_items_and_can_edit_them(): void
    {
        $jobId = $this->leftPendingWithUploads();
        $other = $this->punchIn($this->makeUser($this->tenant, Role::Technician));

        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->patchJson("/api/v1/jobs/{$jobId}/assign", ['technician_id' => $other->id])->assertOk();

        $this->forgetGuards()->actingAsUser($other);
        // The start response already carries them: the app opens the new visit straight from it.
        $started = $this->postJson("/api/v1/jobs/{$jobId}/visits/start", ['service_type' => 'office'])->assertCreated()
            ->assertJsonCount(1, 'data.images')->assertJsonCount(1, 'data.voice_notes')
            ->assertJsonPath('data.service_summary', 'Compressor noisy, needs a part check');
        $visit = $started->json('data.id');
        $show = $this->getJson("/api/v1/visits/{$visit}")->assertOk()
            ->assertJsonPath('data.service_summary', 'Compressor noisy, needs a part check')
            ->assertJsonCount(1, 'data.images')->assertJsonCount(1, 'data.voice_notes');
        $this->assertSame('serial', $show->json('data.images.0.type'));
        // The photo is still the first technician's upload.
        $this->assertSame($this->tech->id, $show->json('data.images.0.uploaded_by'));

        // Editable: the new technician can replace the photo and drop the voice note.
        $this->deleteJson("/api/v1/visits/{$visit}/images/{$show->json('data.images.0.id')}")->assertOk();
        $this->deleteJson("/api/v1/visits/{$visit}/voice-notes/{$show->json('data.voice_notes.0.id')}")->assertOk();
        $this->postJson("/api/v1/visits/{$visit}/images", ['type' => 'serial', 'image' => UploadedFile::fake()->image('serial2.jpg', 400, 300)])->assertCreated();
        $this->getJson("/api/v1/visits/{$visit}")->assertJsonCount(1, 'data.images')->assertJsonCount(0, 'data.voice_notes');
    }

    public function test_the_same_technician_resuming_gets_them_too_and_the_first_visit_keeps_its_charges(): void
    {
        $jobId = $this->leftPendingWithUploads();

        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$jobId}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/visits/{$visit}")->assertJsonCount(1, 'data.images')->assertJsonCount(1, 'data.voice_notes');

        $first = JobVisit::where('job_id', $jobId)->orderBy('id')->first();
        $this->assertSame(0, $first->images()->count());
        $this->assertSame('pending', $first->status);
    }

    public function test_a_first_visit_carries_nothing(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/visits/{$visit}")->assertJsonCount(0, 'data.images')->assertJsonPath('data.service_summary', null);
    }
}
