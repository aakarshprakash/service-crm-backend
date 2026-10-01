<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tech = $this->makeUser($this->tenant, Role::Technician);
    }

    private function addAsset(array $extra = []): int
    {
        return $this->actingAsUser($this->admin)->postJson('/api/v1/assets', array_merge([
            'name' => 'Vacuum pump', 'category' => 'tool', 'serial_no' => 'VP-77', 'purchase_cost' => 1250000,
        ], $extra))->assertCreated()->json('data.id');
    }

    public function test_register_issue_and_return_with_history(): void
    {
        $id = $this->addAsset();
        $this->getJson("/api/v1/assets/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.asset_code', fn ($code) => str_starts_with($code, 'AST-'));

        $this->postJson("/api/v1/assets/{$id}/issue", ['user_id' => $this->tech->id, 'condition' => 'good', 'notes' => 'For AC jobs'])
            ->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.holder.id', $this->tech->id);

        // Can't issue twice or retire while someone holds it.
        $this->postJson("/api/v1/assets/{$id}/issue", ['user_id' => $this->tech->id])->assertStatus(422);
        $this->patchJson("/api/v1/assets/{$id}/status", ['status' => 'retired'])->assertStatus(422);

        // The technician sees it under "my assets", and was notified.
        $this->forgetGuards();
        $this->actingAsUser($this->tech)->getJson('/api/v1/my/assets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Vacuum pump');
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->tech->id, 'type' => 'asset_issued']);

        $this->forgetGuards();
        $this->actingAsUser($this->admin)->postJson("/api/v1/assets/{$id}/return", ['condition' => 'fair', 'notes' => 'Hose worn'])
            ->assertOk()->assertJsonPath('data.status', 'available')->assertJsonPath('data.condition', 'fair');

        $this->getJson("/api/v1/assets/{$id}")->assertOk()
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.user.id', $this->tech->id)
            ->assertJsonPath('data.assignments.0.return_condition', 'fair');
    }

    public function test_lost_needs_a_note_and_counts_by_status(): void
    {
        $id = $this->addAsset();
        $this->addAsset(['name' => 'Ladder', 'category' => 'equipment']);
        $this->postJson("/api/v1/assets/{$id}/issue", ['user_id' => $this->tech->id])->assertOk();

        $this->postJson("/api/v1/assets/{$id}/return", ['condition' => 'damaged', 'status' => 'lost'])
            ->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->postJson("/api/v1/assets/{$id}/return", ['condition' => 'damaged', 'status' => 'lost', 'notes' => 'Stolen from van'])
            ->assertOk()->assertJsonPath('data.status', 'lost');

        $this->getJson('/api/v1/assets')->assertOk()
            ->assertJsonPath('meta.counts.lost', 1)
            ->assertJsonPath('meta.counts.available', 1);
    }

    public function test_only_technicians_can_be_issued_and_technicians_cannot_manage(): void
    {
        $id = $this->addAsset();
        $accountant = $this->makeUser($this->tenant, Role::Accountant);
        $this->postJson("/api/v1/assets/{$id}/issue", ['user_id' => $accountant->id])->assertStatus(422)->assertJsonValidationErrors('user_id');

        $this->forgetGuards();
        $this->actingAsUser($this->tech)->getJson('/api/v1/assets')->assertForbidden();
        $this->postJson('/api/v1/assets', ['name' => 'X', 'category' => 'tool'])->assertForbidden();
    }
}
