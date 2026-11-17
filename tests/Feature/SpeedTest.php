<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\Income;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashCloseService;
use App\Services\LedgerService;
use App\Support\SignedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The shortcuts taken for speed must give the same answers as the slow way they replace. */
class SpeedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    /** One technician collects ₹900 cash and ₹400 by UPI on a job. */
    private function collect(User $tech): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech);
        $action = $this->inTenant($this->tenant, fn () => ActionTakenOption::first());
        $this->forgetGuards()->actingAsUser($this->punchIn($tech));
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $invoice = $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done', 'labour_charge' => 130000, 'payment_method' => 'cash', 'amount_collected' => 90000,
        ])->assertOk()->json('data.invoice.id');
        $this->postJson("/api/v1/invoices/{$invoice}/collect", ['amount' => 40000, 'method' => 'upi'])->assertSuccessful();
    }

    public function test_book_balances_added_up_by_the_database_match_the_entry_by_entry_sum(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $this->collect($tech);
        $today = CarbonImmutable::now($this->tenant->timezone)->toDateString();
        $this->inTenant($this->tenant, function () use ($today) {
            Expense::create(['expense_date' => $today, 'amount' => 34000, 'payment_method' => 'cash', 'paid_to' => 'Sanoop']);
            Expense::create(['expense_date' => $today, 'amount' => 12000, 'payment_method' => 'upi']);
            Income::create(['income_date' => $today, 'amount' => 5000, 'payment_method' => 'cash', 'received_from' => 'Scrap']);
            FundTransfer::create(['transfer_date' => $today, 'direction' => 'cash_to_bank', 'amount' => 20000, 'created_by' => $this->admin->id]);
            FundTransfer::create(['transfer_date' => $today, 'direction' => 'bank_to_cash', 'amount' => 3000, 'created_by' => $this->admin->id]);
        });
        // An office count that comes out short is posted to the cash book too.
        $this->forgetGuards()->actingAsUser($this->admin)
            ->postJson('/api/v1/books/day-close', ['date' => $today, 'opening_cash' => 0, 'counted_cash' => 100, 'remarks' => 'Test difference'])->assertCreated();

        $ledger = app(LedgerService::class);
        $this->inTenant($this->tenant, function () use ($ledger, $today) {
            foreach (['cash', 'bank'] as $book) {
                foreach ([['2000-01-01', $today], [$today, $today], ['2000-01-01', '2020-01-01']] as [$from, $to]) {
                    $this->assertSame($ledger->netOfMovements($this->tenant, $book, $from, $to), $ledger->netBetween($this->tenant, $book, $from, $to), "$book $from..$to");
                }
            }
            $this->assertNotSame(0, $ledger->netBetween($this->tenant, 'cash', '2000-01-01', $today));
        });
    }

    public function test_cash_held_by_every_technician_takes_the_same_few_queries_however_many_there_are(): void
    {
        $techs = collect(range(1, 6))->map(fn () => $this->makeUser($this->tenant, Role::Technician));
        $this->collect($techs[0]);
        $this->collect($techs[3]);

        $cash = app(CashCloseService::class);
        $this->inTenant($this->tenant, function () use ($cash, $techs) {
            DB::enableQueryLog();
            $all = $cash->cashInHandFor($techs->pluck('id'));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(4, $queries);
            $this->assertSame(90000, $all[$techs[0]->id]);
            $this->assertSame(90000, $all[$techs[3]->id]);
            $this->assertSame(0, $all[$techs[1]->id]);
            foreach ($techs as $t) {
                $this->assertSame($all[$t->id], $cash->cashInHand($t));
            }
        });

        // The screens that list everyone stay cheap.
        $this->forgetGuards()->actingAsUser($this->admin);
        DB::enableQueryLog();
        $this->getJson('/api/v1/accounts/overview')->assertOk()->assertJsonCount(6, 'data.technicians');
        $this->assertLessThan(25, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_file_links_stay_the_same_within_a_block_so_devices_can_cache_the_file(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:02:00'));
        $first = SignedUrl::stable('files.image', ['image' => 7], 30);
        $this->travel(10)->minutes();
        $this->assertSame($first, SignedUrl::stable('files.image', ['image' => 7], 30));

        // Valid for at least the asked time, never more than twice it.
        parse_str((string) parse_url($first, PHP_URL_QUERY), $query);
        $left = (int) $query['expires'] - CarbonImmutable::parse('2026-11-16 10:02:00')->getTimestamp();
        $this->assertGreaterThanOrEqual(30 * 60, $left);
        $this->assertLessThanOrEqual(60 * 60, $left);

        $this->travel(25)->minutes();
        $this->assertNotSame($first, SignedUrl::stable('files.image', ['image' => 7], 30));
    }
}
