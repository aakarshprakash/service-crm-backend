<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BrandSpare;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Spares\SpareRowParser;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Brand spares: import the brand's billing Excel, return warranty parts, and match the brand's pending list. */
class BrandSpareTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'Billing Document', 'Billing Date', 'Material', 'Item Description', 'EX CRM ID', 'CUSTOMER NAME', 'PO NUMBER',
        'Issued Quantity', 'Issued Value', 'REC DATE', 'WARRANTY', 'DELIVARY', 'INSPECTION NUMBER', 'LR DATE', 'LR NUMBER',
        'COURIER ', 'RMA NUMBER/CHALLAN TYPE', 'SPARE USED FOR CUSTOMER', 'STATUS FROM COMPANY LIST',
    ];

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    /** An .xlsx like the tracking sheet; DateTime values become real Excel dates. */
    private function sheet(array $rows, array $headers = self::HEADERS, array $extraSheets = []): UploadedFile
    {
        $book = new Spreadsheet;
        $fill = function ($sheet, $rows) use ($headers) {
            $sheet->fromArray($headers);
            foreach ($rows as $i => $row) {
                foreach ($row as $c => $v) {
                    $cell = $sheet->getCell([$c + 1, $i + 2]);
                    if ($v instanceof DateTime) {
                        $cell->setValue(ExcelDate::PHPToExcel($v));
                        $cell->getStyle()->getNumberFormat()->setFormatCode('dd-mm-yyyy');
                    } elseif ($v !== null) {
                        $cell->setValue($v);
                    }
                }
            }
        };
        $fill($book->getActiveSheet()->setTitle('Sheet1'), $rows);
        foreach ($extraSheets as $title => $extra) {
            $fill($book->createSheet()->setTitle($title), $extra);
        }
        $path = tempnam(sys_get_temp_dir(), 'spr').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'spares.xlsx', null, null, true);
    }

    /** Five lines covering each stage, in the tracking sheet's own spelling. Values are synthetic. */
    private function trackingRows(): array
    {
        $d = new DateTime('2026-10-31');

        return [
            // Warranty, sent by courier, but still on the brand's pending list.
            ['5790000001', $d, 'GSTEST0001', 'THERMOSTAT', '11112222333344', 'CUSTOMER ONE', 'PO20260000001', 1, 220.69, null, 'WARRENTY', '150000001', 'F3925000001W', '24-11-2026', 'C1000001', 'SPEED AND SAFE', 'MANUAL 12-11-2026', null, 'PENDING'],
            // Warranty, nothing done yet.
            ['5790000001', $d, 'GSTEST0002', 'FAN MOTOR IDU', '11112222333355', 'CUSTOMER TWO', 'PO20260000002', 1, 1568.63, null, 'WARRANTY', '150000001'],
            // Out of warranty.
            ['5510000001', $d, 'GSTEST0003', 'GEAR CASE - 8 KG', null, null, 'OW BIlling', 2, 932.93, null, null, '150000002'],
            // Warranty, inspected, sent back fresh, not yet with the courier.
            ['5790000002', $d, 'GSTEST0004', 'LOUVER 12K', '11112222333366', 'CUSTOMER THREE', 'PO20260000003', 1, 148.97, null, 'WARRENTY', '150000003', 'WI3925000001/FRESH 05-11-2026'],
            // Warranty the brand charged as OW.
            ['5790000003', $d, 'GSTEST0005', 'INLET VALVE', '11112222333377', null, 'PO20260000004', 1, 250.1, null, 'WARRANTY', null, '3925000002W', 'ow billed 31-08-2026', 'C1000002', 'SPEED AND SAFE', 'DC2026000001'],
        ];
    }

    private function importTracking(): void
    {
        $this->post('/api/v1/spares/import', ['file' => $this->sheet($this->trackingRows())], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_the_parser_reads_the_hand_typed_tracking_sheet(): void
    {
        $p = new SpareRowParser;
        $row = $p->parse([
            'billing_doc' => 5790000009.0, 'material_code' => 'gstest0009', 'ticket_no' => 9042650039759.0, 'value' => 800.22,
            'coverage' => 'WARRENTY', 'inspection' => '3925000003W/WI3925000001', 'lr_date' => 'ow billed 31-08-2026',
            'lr_number' => 'C1000003', 'courier' => 'SPEED AND SAFE', 'rma' => 'DC2026000002&MANUAL 16-10-2026', 'company' => 'PENDING ',
        ]);

        $this->assertSame('5790000009', $row['billing_doc']);
        $this->assertSame('9042650039759', $row['ticket_no']);
        $this->assertSame('GSTEST0009', $row['material_code']);
        $this->assertSame(80022, $row['value']);
        $this->assertSame('warranty', $row['coverage']);
        $this->assertSame('3925000003W / WI3925000001', $row['inspection_no']);
        $this->assertSame('DC2026000002 / MANUAL 16-10-2026', $row['rma_no']);
        $this->assertTrue($row['ow_billed']);
        $this->assertSame('2026-08-31', $row['ow_billed_on']);
        $this->assertNull($row['lr_date']);
        $this->assertTrue($row['company_pending']);
        $this->assertSame('closed', BrandSpare::stageFor($row));

        $odd = $p->parse(['billing_doc' => 'X1', 'material_code' => 'M1', 'coverage' => 'OW', 'inspection' => '5513500001', 'courier' => 'PENDING', 'rma' => 'NOT DELIVERED']);
        $this->assertSame('out_of_warranty', $odd['coverage']);
        $this->assertSame('5513500001', $odd['ow_bill_no']);
        $this->assertNull($odd['inspection_no']);
        $this->assertNull($odd['courier']);
        $this->assertSame('RMA: NOT DELIVERED; Courier: PENDING', $odd['remarks']);

        $this->assertSame('stock', $p->parse(['billing_doc' => 'X2', 'material_code' => 'M2', 'customer_name' => 'AJ BILLED'])['coverage']);

        // Dates typed with slashes stay whole, even in cells split on "/".
        $slashed = $p->parse(['billing_doc' => 'X4', 'material_code' => 'M4', 'coverage' => 'WARRANTY', 'lr_number' => '24/11/2026', 'inspection' => 'WI3925000001/FRESH 05/11/2026']);
        $this->assertSame('2026-11-24', $slashed['lr_date']);
        $this->assertSame('WI3925000001', $slashed['inspection_no']);
        $this->assertSame('fresh', $slashed['return_type']);
        $this->assertNull($slashed['remarks']);
        $this->assertNull($p->parse(['billing_doc' => null, 'material_code' => 'M3']));
    }

    public function test_import_previews_then_adds_lines_links_jobs_and_skips_duplicates(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Customer One']);
        $job = $this->makeJob($this->tenant, $customer, null, ['crm_call_id' => '11112222333344']);
        $this->actingAsUser($this->admin);

        $preview = $this->post('/api/v1/spares/import', ['file' => $this->sheet($this->trackingRows()), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.lines', 5)
            ->assertJsonPath('data.new', 5)
            ->assertJsonPath('data.linked_to_jobs', 1)
            ->assertJsonPath('data.company_pending', 1)
            ->json('data');
        $this->assertSame(['count' => 1, 'value' => 156863], $preview['by_stage']['inspect']);
        $this->assertSame(1, $preview['by_stage']['ready']['count']);
        $this->assertSame(1, $preview['by_stage']['sent']['count']);
        $this->assertSame(2, $preview['by_stage']['closed']['count']);
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => BrandSpare::count()));

        // A second sheet repeating a line from Sheet1 (like "OW MISTAKE") is counted once.
        $file = $this->sheet($this->trackingRows(), extraSheets: ['OW MISTAKE' => [$this->trackingRows()[4]]]);
        $this->post('/api/v1/spares/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.new', 5)->assertJsonPath('message', '5 part lines added.');

        $sent = $this->inTenant($this->tenant, fn () => BrandSpare::where('material_code', 'GSTEST0001')->first());
        $this->assertSame('sent', $sent->stage);
        $this->assertSame($job->id, $sent->job->id);
        $this->assertSame('2026-11-24', $sent->lr_date->toDateString());
        $this->assertSame('C1000001', $sent->lr_number);
        $this->assertSame(22069, $sent->value);
        $this->assertSame('2026-10-31', $sent->billing_date->toDateString());
        $this->assertSame('fresh', $this->inTenant($this->tenant, fn () => BrandSpare::where('material_code', 'GSTEST0004')->value('return_type')));

        // Uploading the same file again adds nothing.
        $this->post('/api/v1/spares/import', ['file' => $this->sheet($this->trackingRows())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.new', 0)->assertJsonPath('data.already_added', 5);
        $this->assertSame(5, $this->inTenant($this->tenant, fn () => BrandSpare::count()));
    }

    public function test_a_file_without_the_billing_columns_is_refused_with_a_plain_message(): void
    {
        $this->actingAsUser($this->admin);
        $this->post('/api/v1/spares/import', ['file' => $this->sheet([['a', 'b']], ['Name', 'Phone'])], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'No part lines found. The sheet needs "Billing Document" and "Material" columns.');
    }

    public function test_the_summary_counts_stages_and_flags_sent_parts_the_brand_still_shows_pending(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();

        $this->getJson('/api/v1/spares/summary')->assertOk()
            ->assertJsonPath('data.stages.inspect.count', 1)
            ->assertJsonPath('data.stages.sent.value', 22069)
            ->assertJsonPath('data.mismatch.count', 1)
            ->assertJsonPath('data.couriers.0', 'SPEED AND SAFE');

        $this->getJson('/api/v1/spares?attention=mismatch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.material_code', 'GSTEST0001');
        $this->getJson('/api/v1/spares?stage=closed')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/spares?search=CUSTOMER TWO')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_bulk_actions_move_parts_through_the_return_and_skip_out_of_warranty_lines(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();
        $ids = $this->inTenant($this->tenant, fn () => BrandSpare::pluck('id', 'material_code'));

        $this->postJson('/api/v1/spares/bulk', ['action' => 'inspect', 'ids' => [$ids['GSTEST0002']]])
            ->assertStatus(422)->assertJsonPath('errors.inspection_no.0', 'Enter the inspection number.');
        $this->postJson('/api/v1/spares/bulk', ['action' => 'inspect', 'ids' => [$ids['GSTEST0002']], 'inspection_no' => '3925000004W'])
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('ready', $this->inTenant($this->tenant, fn () => BrandSpare::find($ids['GSTEST0002'])->stage));

        $this->postJson('/api/v1/spares/bulk', [
            'action' => 'send', 'ids' => [$ids['GSTEST0002'], $ids['GSTEST0004'], $ids['GSTEST0003']],
            'lr_date' => '2026-11-25', 'lr_number' => 'C1009999', 'courier' => 'SPEED AND SAFE',
        ])->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('message', '2 parts updated. 1 skipped: out of warranty, stock, or already past this step.');

        $this->assertSame(2, $this->inTenant($this->tenant, fn () => BrandSpare::where('lr_number', 'C1009999')->where('stage', 'sent')->count()));
        $this->assertSame('closed', $this->inTenant($this->tenant, fn () => BrandSpare::find($ids['GSTEST0003'])->stage));

        // Every line the filter matches, without ticking them one by one.
        $this->postJson('/api/v1/spares/bulk', ['action' => 'company_cleared', 'filter' => ['attention' => 'mismatch']])
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->getJson('/api/v1/spares/summary')->assertJsonPath('data.mismatch.count', 0);
    }

    public function test_bulk_on_the_whole_list_needs_a_known_filter_and_skips_parts_already_sent(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();

        // An empty or unknown filter would match every part: refused.
        $this->postJson('/api/v1/spares/bulk', ['action' => 'delete', 'filter' => ['x' => '1']])
            ->assertStatus(422)->assertJsonPath('errors.filter.0', 'Choose the parts to change first.');
        $this->postJson('/api/v1/spares/bulk', ['action' => 'delete', 'filter' => ['search' => ['a']]])->assertStatus(422);
        $this->assertSame(5, $this->inTenant($this->tenant, fn () => BrandSpare::count()));

        // "All" on purpose: the sent line keeps its LR, the warranty lines move on, the rest are skipped.
        $this->postJson('/api/v1/spares/bulk', ['action' => 'send', 'filter' => ['all' => '1'], 'lr_date' => '2026-11-25', 'courier' => 'SPEED AND SAFE', 'lr_number' => 'C1000099'])
            ->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped', 3);
        $this->assertSame('C1000001', $this->inTenant($this->tenant, fn () => BrandSpare::where('material_code', 'GSTEST0001')->value('lr_number')));
    }

    public function test_a_part_added_by_hand_is_not_added_again_when_its_bill_is_uploaded(): void
    {
        $this->actingAsUser($this->admin);
        $this->postJson('/api/v1/spares', ['billing_doc' => '5790000001', 'billing_date' => '2026-10-31', 'material_code' => 'gstest0002', 'quantity' => 1, 'value' => 156863, 'coverage' => 'warranty'])
            ->assertCreated()->assertJsonPath('data.material_code', 'GSTEST0002');

        $this->post('/api/v1/spares/import', ['file' => $this->sheet($this->trackingRows())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.new', 4)->assertJsonPath('data.already_added', 1);

        // Emptying a required field gets the plain message, not a type error.
        $id = $this->inTenant($this->tenant, fn () => BrandSpare::where('material_code', 'GSTEST0002')->value('id'));
        $this->patchJson("/api/v1/spares/{$id}", ['billing_doc' => null])->assertStatus(422)
            ->assertJsonPath('errors.billing_doc.0', "Enter the billing document number from the brand's bill.");
    }

    public function test_the_brands_pending_list_replaces_the_previous_one(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();

        // The brand now lists the fan motor and an unknown line; the thermostat is no longer pending.
        $list = fn () => $this->sheet([['5790000001', null, 'GSTEST0002'], ['5799999999', null, 'GSXX0001']], ['Billing Document', 'Billing Date', 'Material']);
        $this->post('/api/v1/spares/company-pending', ['file' => $list(), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.matched', 1)->assertJsonPath('data.not_found', 1)->assertJsonPath('data.cleared', 1);
        $this->assertTrue($this->inTenant($this->tenant, fn () => BrandSpare::where('material_code', 'GSTEST0001')->value('company_pending')));

        $this->post('/api/v1/spares/company-pending', ['file' => $list()], ['Accept' => 'application/json'])->assertOk();
        $pending = $this->inTenant($this->tenant, fn () => BrandSpare::where('company_pending', true)->pluck('material_code')->all());
        $this->assertSame(['GSTEST0002'], $pending);
    }

    public function test_export_writes_the_tracking_sheet_columns_and_uploads_back_without_duplicates(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();

        $response = $this->get('/api/v1/spares/export')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $copy = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        $sheet = IOFactory::load($copy)->getActiveSheet();
        $this->assertSame('Billing Document', $sheet->getCell('A1')->getValue());
        $this->assertSame('INSPECTION NUMBER', $sheet->getCell('L1')->getValue());
        $this->assertContains('11112222333344', array_column($sheet->toArray(), 4));

        $this->post('/api/v1/spares/import', ['file' => new UploadedFile($copy, 'export.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.new', 0)->assertJsonPath('data.already_added', 5);
    }

    public function test_one_part_can_be_added_and_edited_by_hand(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Customer Three']);
        $job = $this->makeJob($this->tenant, $customer, null, ['crm_call_id' => '11112222333366']);
        $this->actingAsUser($this->admin);

        $id = $this->postJson('/api/v1/spares', ['billing_doc' => '5790000010', 'billing_date' => '2026-11-20', 'material_code' => 'GSTEST0010', 'value' => 104900, 'coverage' => 'warranty'])
            ->assertCreated()->assertJsonPath('data.stage', 'inspect')->json('data.id');

        $this->patchJson("/api/v1/spares/{$id}", ['ticket_no' => '11112222333366', 'inspection_no' => '3925000005W'])
            ->assertOk()->assertJsonPath('data.stage', 'ready')->assertJsonPath('data.job.id', $job->id);

        $this->postJson('/api/v1/spares', ['material_code' => 'X'])->assertStatus(422)
            ->assertJsonPath('errors.billing_doc.0', 'Enter the billing document number from the brand\'s bill.');
    }

    public function test_technicians_cannot_open_spares_and_companies_see_only_their_own(): void
    {
        $this->actingAsUser($this->admin);
        $this->importTracking();

        $tech = $this->makeUser($this->tenant, Role::Technician);
        $this->forgetGuards()->actingAsUser($tech)->getJson('/api/v1/spares')->assertForbidden();

        ['admin' => $otherAdmin] = $this->makeTenant('other');
        $this->forgetGuards()->actingAsUser($otherAdmin)->getJson('/api/v1/spares')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/spares/summary')->assertJsonPath('data.stages.sent.count', 0);
    }
}
