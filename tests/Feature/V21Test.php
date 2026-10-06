<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\LeaveType;
use App\Models\PunchLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v2.1: UPI QR, cash / bank books, geo-fenced attendance, leave and payroll. */
class V21Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    private function walkIn(array $extra = []): array
    {
        return $this->actingAsUser($this->admin)->postJson('/api/v1/walk-in-bills', array_merge([
            'customer' => ['name' => 'Asha', 'phone' => '9811100001'],
            'items' => [['type' => 'service', 'description' => 'Gas top-up', 'quantity' => 1, 'unit_price' => 100000]],
            'payment_method' => 'cash', 'amount_paid' => 40000,
        ], $extra))->assertCreated()->json('data');
    }

    public function test_upi_account_link_on_invoice_and_pdf(): void
    {
        $this->actingAsUser($this->admin)->postJson('/api/v1/master/upi-accounts', ['name' => 'Main', 'vpa' => 'bad id', 'payee_name' => 'Acme'])
            ->assertStatus(422)->assertJsonValidationErrors('vpa');
        $this->postJson('/api/v1/master/upi-accounts', ['name' => 'Main', 'vpa' => 'acme@okhdfc', 'payee_name' => 'Acme Services', 'is_default' => true])->assertCreated();
        $this->postJson('/api/v1/master/upi-accounts', ['name' => 'Second', 'vpa' => 'acme2@ybl', 'payee_name' => 'Acme', 'is_default' => true])->assertCreated();
        $this->getJson('/api/v1/master/lookups')->assertOk()->assertJsonCount(2, 'data.upi_accounts')
            ->assertJsonPath('data.upi_accounts.0.vpa', 'acme2@ybl'); // only one default, the latest

        $bill = $this->walkIn();
        $res = $this->getJson("/api/v1/invoices/{$bill['id']}")->assertOk()
            ->assertJsonPath('data.upi.vpa', 'acme2@ybl')->assertJsonPath('data.upi.amount', 60000);
        $this->assertStringStartsWith('upi://pay?pa=acme2%40ybl&pn=Acme&am=600.00&cu=INR&tn=Invoice', $res->json('data.upi.link'));
        $this->get("/api/v1/invoices/{$bill['id']}/pdf")->assertOk();
    }

    public function test_cash_and_bank_books_transfers_opening_and_receivables(): void
    {
        $today = CarbonImmutable::now($this->tenant->timezone)->toDateString();
        $this->actingAsUser($this->admin)->putJson('/api/v1/books/opening', ['date' => CarbonImmutable::parse($today)->startOfMonth()->toDateString(), 'cash' => 500000, 'bank' => 1000000])->assertOk();
        $this->walkIn(); // ₹400 cash in, ₹600 receivable
        $this->walkIn(['customer' => ['name' => 'Ravi', 'phone' => '9811100002'], 'payment_method' => 'upi', 'amount_paid' => 100000, 'reference_no' => 'UTR1']);
        $this->postJson('/api/v1/books/transfers', ['transfer_date' => $today, 'direction' => 'cash_to_bank', 'amount' => 200000])->assertCreated();

        $this->getJson("/api/v1/books/ledger?book=cash&from=$today&to=$today")->assertOk()
            ->assertJsonPath('data.total_in', 40000)->assertJsonPath('data.total_out', 200000)
            ->assertJsonPath('data.closing', 500000 + 40000 - 200000);
        $this->getJson("/api/v1/books/ledger?book=bank&from=$today&to=$today")->assertOk()
            ->assertJsonPath('data.closing', 1000000 + 100000 + 200000)->assertJsonCount(2, 'data.entries');

        $this->getJson('/api/v1/books/receivables')->assertOk()->assertJsonPath('data.totals.total', 60000)
            ->assertJsonPath('data.customers', 1)->assertJsonPath('data.rows.0.d0_30', 60000);
        $this->getJson('/api/v1/books/overview')->assertOk()->assertJsonPath('data.balances.cash', 340000)
            ->assertJsonPath('data.month.income', 140000)->assertJsonCount(6, 'data.trend');
        $this->getJson('/api/v1/books/profit-loss')->assertOk()->assertJsonPath('data.income.1.current', 140000)->assertJsonPath('data.net.current', 140000);
    }

    public function test_geofenced_punch_flags_or_blocks(): void
    {
        $this->inTenant($this->tenant, fn () => Branch::first()->update(['lat' => 12.9716, 'lng' => 77.5946, 'geofence_radius' => 200]));
        $staff = $this->makeUser($this->tenant, Role::Coordinator);
        $far = ['lat' => 12.9900, 'lng' => 77.5946]; // ~2 km north

        // Flag mode: allowed but recorded as outside.
        $this->actingAsUser($this->admin)->putJson('/api/v1/settings/preferences', ['attendance' => ['geofence' => 'flag']])->assertOk();
        $this->forgetGuards()->actingAsUser($staff)->postJson('/api/v1/punch', ['type' => 'in'] + $far)->assertOk()->assertJsonPath('data.within_fence', false);
        $this->postJson('/api/v1/punch', ['type' => 'out', 'lat' => 12.9717, 'lng' => 77.5947])->assertOk()->assertJsonPath('data.within_fence', true);

        // Enforce mode: outside (or no location) is refused.
        $this->forgetGuards()->actingAsUser($this->admin)->putJson('/api/v1/settings/preferences', ['attendance' => ['geofence' => 'enforce']])->assertOk();
        $this->forgetGuards()->actingAsUser($staff)->postJson('/api/v1/punch', ['type' => 'in'] + $far)->assertStatus(422)->assertJsonValidationErrors('location');
        $this->postJson('/api/v1/punch', ['type' => 'in'])->assertStatus(422)->assertJsonValidationErrors('location');
        // Technicians are exempt unless the company turns it on.
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $this->forgetGuards()->actingAsUser($tech)->postJson('/api/v1/punch', ['type' => 'in'] + $far)->assertOk();

        $this->assertSame(3, $this->inTenant($this->tenant, fn () => PunchLog::count()));
        $this->forgetGuards()->actingAsUser($this->admin)->getJson('/api/v1/hr/attendance/daily')->assertOk()->assertJsonPath('meta.present', 2);
    }

    public function test_leave_apply_approve_balance_and_attendance(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $casual = $this->inTenant($this->tenant, fn () => LeaveType::where('code', 'CL')->first());
        $monday = CarbonImmutable::now($this->tenant->timezone)->next('Monday')->addWeek();

        $id = $this->actingAsUser($tech)->postJson('/api/v1/my/leaves', [
            'leave_type_id' => $casual->id, 'from_date' => $monday->toDateString(), 'to_date' => $monday->addDays(6)->toDateString(), 'reason' => 'Wedding',
        ])->assertCreated()->assertJsonPath('data.days', 6)->json('data.id'); // Sunday is a weekly off
        $this->postJson('/api/v1/my/leaves', ['leave_type_id' => $casual->id, 'from_date' => $monday->addDay()->toDateString(), 'to_date' => $monday->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('from_date'); // overlap
        $this->postJson('/api/v1/my/leaves', ['leave_type_id' => $casual->id, 'from_date' => $monday->addWeeks(2)->toDateString(), 'to_date' => $monday->addWeeks(2)->addDays(7)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('leave_type_id'); // 6 pending + 7 > 12

        $this->getJson('/api/v1/hr/leave-requests')->assertForbidden();
        $this->forgetGuards()->actingAsUser($this->admin)->patchJson("/api/v1/hr/leave-requests/$id", ['status' => 'rejected'])->assertStatus(422);
        $this->patchJson("/api/v1/hr/leave-requests/$id", ['status' => 'approved'])->assertOk();

        $this->forgetGuards()->actingAsUser($tech)->getJson('/api/v1/my/leaves')->assertOk()
            ->assertJsonPath('data.balances.0.leave_type.code', 'CL')->assertJsonPath('data.balances.0.remaining', 6);
        $this->getJson('/api/v1/my/attendance?month='.$monday->format('Y-m'))->assertOk();
    }

    public function test_new_reports_render_and_export(): void
    {
        $this->walkIn();
        foreach (['cash-book', 'bank-book', 'receipts', 'attendance', 'leave', 'payroll'] as $key) {
            $this->actingAsUser($this->admin)->getJson("/api/v1/reports/$key")->assertOk()->assertJsonStructure(['data' => ['columns', 'rows', 'summary']]);
        }
        $this->getJson('/api/v1/reports/cash-book')->assertJsonPath('data.rows.0.in', 40000);
        $this->get('/api/v1/reports/attendance/export?format=xlsx')->assertOk();
    }

    public function test_payroll_from_attendance_to_expenses_and_payslips(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $month = CarbonImmutable::now($this->tenant->timezone)->subMonthNoOverflow()->format('Y-m');
        $start = CarbonImmutable::parse("$month-01");

        $this->actingAsUser($this->admin)->putJson("/api/v1/hr/employees/{$tech->id}", [
            'monthly_salary' => 3000000, 'employee_code' => 'E001', 'designation' => 'Technician',
            'components' => [['name' => 'HRA', 'type' => 'earning', 'amount' => 500000], ['name' => 'PF', 'type' => 'deduction', 'amount' => 180000]],
        ])->assertOk();
        // Present on the first working day only; everything else is absent → large LOP.
        $this->inTenant($this->tenant, fn () => PunchLog::create(['user_id' => $tech->id, 'type' => 'in', 'created_at' => $start->addDays(1)->setTime(4, 0)]));
        $this->postJson('/api/v1/hr/attendance/adjust', ['user_id' => $tech->id, 'date' => $start->addDays(2)->toDateString(), 'status' => 'present'])->assertOk();

        $this->postJson('/api/v1/hr/payroll', ['month' => CarbonImmutable::now()->addMonth()->format('Y-m')])->assertStatus(422);
        $runId = $this->postJson('/api/v1/hr/payroll', ['month' => $month])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/payroll', ['month' => $month])->assertStatus(422);

        $run = $this->getJson("/api/v1/hr/payroll/$runId")->assertOk()->json('data');
        $slip = collect($run['payslips'])->firstWhere('user_id', $tech->id);
        $this->assertSame(3000000, $slip['gross']);
        $this->assertGreaterThan(0, $slip['lop_amount']);
        $this->assertSame(max(0, 3000000 - 180000 - $slip['lop_amount']), $slip['net_pay']);

        $this->patchJson("/api/v1/hr/payroll/$runId/payslips/{$slip['id']}", ['lop_days' => 0, 'bonus' => 100000])->assertOk()
            ->assertJsonPath('data.net_pay', 3000000 + 100000 - 180000);
        $this->postJson("/api/v1/hr/payroll/$runId/pay", ['payment_method' => 'bank_transfer', 'paid_on' => CarbonImmutable::now($this->tenant->timezone)->toDateString()])->assertStatus(422);
        $this->postJson("/api/v1/hr/payroll/$runId/finalize")->assertOk();
        $this->postJson('/api/v1/hr/attendance/adjust', ['user_id' => $tech->id, 'date' => $start->toDateString(), 'status' => 'absent'])->assertStatus(422); // locked
        $this->postJson("/api/v1/hr/payroll/$runId/pay", ['payment_method' => 'bank_transfer', 'paid_on' => CarbonImmutable::now($this->tenant->timezone)->toDateString()])->assertOk();

        $this->assertSame(2920000, (int) $this->inTenant($this->tenant, fn () => Expense::where('user_id', $tech->id)->sum('amount')));
        $this->forgetGuards()->actingAsUser($tech)->getJson('/api/v1/my/payslips')->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/my/payslips/{$slip['id']}/pdf")->assertOk();
        $this->getJson('/api/v1/hr/payroll')->assertForbidden();
    }
}
