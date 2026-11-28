<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BooksService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Faster code paths must give exactly the figures the slower ones gave. */
class PerformanceTuningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tenant->forceFill(['gstin' => '32BGKPA0180J1ZT', 'state' => 'Kerala'])->save();
        $this->actingAsUser($this->admin);
    }

    public function test_the_six_month_trend_matches_a_summary_per_month(): void
    {
        $fuel = $this->inTenant($this->tenant, fn () => ExpenseCategory::where('name', 'Fuel & travel')->firstOrFail());
        foreach ([0, 1, 3] as $back) {
            $this->travelTo(now()->subMonthsNoOverflow($back)->startOfMonth()->addDays(2)->setTime(10, 0));
            $this->postJson('/api/v1/walk-in-bills', [
                'customer' => ['name' => "C{$back}", 'phone' => '98111000'.str_pad((string) $back, 2, '0', STR_PAD_LEFT)], 'is_gst' => true,
                'items' => [['type' => 'service', 'description' => 'Repair', 'quantity' => 1, 'unit_price' => 118000 + $back * 1000]],
                'payment_method' => 'cash', 'amount_paid' => 50000 + $back * 1000,
            ])->assertCreated();
            $this->postJson('/api/v1/expenses', ['expense_date' => now($this->tenant->timezone)->toDateString(), 'expense_category_id' => $fuel->id,
                'amount' => 7000 + $back, 'payment_method' => 'cash'])->assertCreated();
            $this->travelBack();
        }

        $trend = $this->inTenant($this->tenant, fn () => app(LedgerService::class)->monthlyTrend($this->tenant));
        $books = app(BooksService::class);
        $now = CarbonImmutable::now($this->tenant->timezone);
        foreach (array_reverse($trend) as $back => $row) {
            $m = $now->startOfMonth()->subMonths($back);
            $s = $this->inTenant($this->tenant, fn () => $books->summary($m->toDateString(), ($back === 0 ? $now : $m->endOfMonth())->toDateString(), $this->tenant->timezone));
            $this->assertSame(['month' => $m->format('Y-m'), 'income' => $s['income'], 'expense' => $s['expense'], 'net' => $s['net']], $row, "month -{$back}");
        }
        $this->assertGreaterThan(0, $trend[5]['income']);
        $this->assertSame(0, $trend[3]['income']);
    }

    public function test_a_token_is_written_at_most_once_a_minute(): void
    {
        $token = $this->admin->createToken('web')->plainTextToken;
        $this->forgetGuards();
        $call = fn () => $this->forgetGuards()->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $call();
        $first = PersonalAccessToken::latest('id')->first()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(20)->seconds();
        DB::enableQueryLog();
        $call();
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update `personal_access_tokens`'));
        $this->assertCount(0, $writes);
        $this->assertTrue(PersonalAccessToken::latest('id')->first()->last_used_at->equalTo($first));

        $this->travel(2)->minutes();
        $call();
        $this->assertTrue(PersonalAccessToken::latest('id')->first()->last_used_at->gt($first));
    }
}
