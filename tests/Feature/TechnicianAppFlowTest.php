<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\ProductCategory;
use App\Models\TechnicianStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a technician can record from the app: product details (model and category locked once set),
 * job updates, customer location, cancellation requests, bill type, and parts allocated to them.
 */
class TechnicianAppFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->makeTenant();
        $this->tech = $this->makeUser($this->ctx['tenant'], Role::Technician);
    }

    private function jobWithProduct(array $productAttributes = [])
    {
        $tenant = $this->ctx['tenant'];
        $customer = $this->makeCustomer($tenant);
        $product = $this->inTenant($tenant, function () use ($customer, $productAttributes) {
            $p = $customer->products()->first();
            $p->forceFill($productAttributes + ['product_id' => null, 'model_no' => null, 'category_id' => null, 'brand_id' => null])->save();

            return $p;
        });

        return [$this->makeJob($tenant, $customer, $this->tech, ['customer_product_id' => $product->id]), $product];
    }

    /** @return array{0: InventoryItem, 1: Branch} */
    private function itemInBranch(float $quantity = 10, int $price = 50000): array
    {
        return $this->inTenant($this->ctx['tenant'], function () use ($quantity, $price) {
            $branch = Branch::query()->first() ?? Branch::create(['name' => 'Main']);
            $item = InventoryItem::create(['code' => 'CAP-'.uniqid(), 'name' => 'Capacitor', 'type' => 'spare', 'unit_of_measure' => 'nos', 'unit_price' => $price, 'is_active' => true]);
            app(\App\Services\InventoryService::class)->stockIn($branch->id, $item, $quantity, 30000, ['created_by' => null]);

            return [$item, $branch];
        });
    }

    private function allocate(InventoryItem $item, Branch $branch, float $quantity)
    {
        return $this->forgetGuards()->actingAsUser($this->ctx['admin'])->postJson('/api/v1/inventory/technician-stock/allocate', [
            'technician_id' => $this->tech->id, 'item_id' => $item->id, 'branch_id' => $branch->id, 'quantity' => $quantity,
        ]);
    }

    private function startVisit($job): int
    {
        $this->forgetGuards();
        $this->punchIn($this->tech);
        $this->actingAsUser($this->tech);

        return $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
    }

    public function test_office_entered_brand_type_and_model_are_locked_and_the_rest_is_editable(): void
    {
        $category = $this->inTenant($this->ctx['tenant'], fn () => ProductCategory::create(['name' => 'Split AC']));
        $other = $this->inTenant($this->ctx['tenant'], fn () => ProductCategory::create(['name' => 'Fridge']));
        $brand = $this->inTenant($this->ctx['tenant'], fn () => \App\Models\Brand::create(['name' => 'Samsung']));
        [$job, $product] = $this->jobWithProduct(['model_no' => 'GLS-18', 'category_id' => $category->id, 'brand_id' => $brand->id]);

        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", [
            'serial_no' => 'SN-9', 'purchase_date' => '2026-01-15', 'warranty_type' => 'amc', 'warranty_expiry' => '2027-01-15',
            'model_no' => 'gls-18', 'category_id' => $category->id, 'brand_id' => $brand->id, // sent back unchanged: fine
        ])->assertOk();
        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'serial_no' => 'SN-9', 'warranty_type' => 'amc', 'model_no' => 'GLS-18']);

        // A real change to what the office entered is refused, and nothing in that save is kept.
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => 'OTHER', 'serial_no' => 'SN-X'])->assertStatus(422)->assertJsonValidationErrors('model_no');
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['category_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'model_no' => 'GLS-18', 'category_id' => $category->id, 'serial_no' => 'SN-9']);

        // A blank field in a save doesn't wipe what's recorded.
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => null, 'category_id' => null, 'brand_id' => null])->assertOk();
        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'model_no' => 'GLS-18', 'category_id' => $category->id, 'brand_id' => $brand->id]);
    }

    public function test_filling_a_gap_on_a_catalogue_unit_keeps_what_the_link_supplied(): void
    {
        $tenant = $this->ctx['tenant'];
        [$catalogue, $brand, $category] = $this->inTenant($tenant, function () {
            $brand = \App\Models\Brand::create(['name' => 'Lloyd']);
            $category = ProductCategory::create(['name' => 'Split AC']);

            return [\App\Models\Product::create(['brand_id' => $brand->id, 'model_name' => 'GLS-18']), $brand, $category];
        });
        [$job, $product] = $this->jobWithProduct();
        $this->inTenant($tenant, fn () => $product->update(['product_id' => $catalogue->id]));

        // Unchanged values sent back leave the link alone; changing the catalogue's model is refused.
        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => 'gls-18', 'brand_id' => $brand->id, 'serial_no' => 'S1'])->assertOk();
        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'product_id' => $catalogue->id, 'serial_no' => 'S1']);
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => 'GLS-24'])->assertStatus(422);

        // The missing product type can be added.
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['category_id' => $category->id])->assertOk();
        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'product_id' => null, 'model_no' => 'GLS-18', 'brand_id' => $brand->id, 'category_id' => $category->id]);
    }

    public function test_in_warranty_needs_the_purchase_date(): void
    {
        [$job, $product] = $this->jobWithProduct();
        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", ['warranty_type' => 'in_warranty', 'purchase_date' => null])
            ->assertStatus(422)->assertJsonPath('errors.purchase_date.0', 'Enter the purchase date: the unit is marked in warranty.');
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['warranty_type' => 'in_warranty', 'purchase_date' => '2026-02-01'])->assertOk();
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['warranty_type' => 'out_of_warranty', 'purchase_date' => null])->assertOk();

        // The office's forms follow the same rule.
        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->patchJson("/api/v1/customers/{$product->customer_id}/products/{$product->id}", ['warranty_type' => 'in_warranty'])->assertStatus(422)->assertJsonValidationErrors('purchase_date');
        $this->postJson('/api/v1/customers', ['name' => 'Ravi', 'phone' => '9811100777', 'products' => [['warranty_type' => 'in_warranty']]])
            ->assertStatus(422)->assertJsonValidationErrors('products.0.purchase_date');
    }

    public function test_photo_sections_are_set_by_the_company_without_an_app_update(): void
    {
        $admin = $this->ctx['admin'];
        $this->actingAsUser($admin);
        $this->getJson('/api/v1/master/lookups')->assertOk()->assertJsonPath('data.photo_types.0.key', 'bill')->assertJsonCount(5, 'data.photo_types');

        // Rename one, drop the rest, add a new one: the list shrinks and stays that way.
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => [
            ['key' => 'bill', 'label' => 'Customer bill'],
            ['label' => 'Gas pressure gauge reading and more'],
        ]])->assertOk();
        $types = $this->getJson('/api/v1/master/lookups')->assertJsonCount(2, 'data.photo_types')->json('data.photo_types');
        $this->assertSame(['key' => 'bill', 'label' => 'Customer bill'], $types[0]);
        $this->assertLessThanOrEqual(20, strlen($types[1]['key']));
        // A section that's gone keeps its name for the photos already taken under it.
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.photo_labels.complaint_part', 'Faulty part')->assertJsonPath("data.photo_labels.{$types[1]['key']}", 'Gas pressure gauge reading and more');

        // Saving other preferences leaves the list as it is.
        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['require_signature' => false]])->assertOk();
        $this->getJson('/api/v1/master/lookups')->assertJsonCount(2, 'data.photo_types');

        // Names must be unique, and at least one section must remain.
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => []])->assertStatus(422)->assertJsonValidationErrors('photo_types');
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => [['label' => 'Same'], ['label' => 'same']]])->assertStatus(422);
        // A section can't take over a key the app files separately.
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => [['key' => 'signature', 'label' => 'Signature']]])->assertOk();
        $this->assertNotContains('signature', array_column($this->getJson('/api/v1/master/lookups')->json('data.photo_types'), 'key'));
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => $types])->assertOk();

        // A technician uploads to the new section, can't invent one, and can't change the list.
        $this->forgetGuards()->actingAsUser($this->tech);
        $this->putJson('/api/v1/settings/preferences', ['photo_types' => [['label' => 'Mine']]])->assertForbidden();
        [$job] = $this->jobWithProduct();
        $visit = $this->startVisit($job);
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->image('a.jpg', 100, 100);
        \Illuminate\Support\Facades\Storage::fake('private');
        $this->post("/api/v1/visits/{$visit}/images", ['type' => $types[1]['key'], 'image' => $file()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.type', $types[1]['key']);
        $this->post("/api/v1/visits/{$visit}/images", ['type' => 'made_up', 'image' => $file()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->post("/api/v1/visits/{$visit}/images", ['type' => 'signature', 'image' => $file()], ['Accept' => 'application/json'])->assertStatus(422);
        // Installed apps still send the original sections.
        $this->post("/api/v1/visits/{$visit}/images", ['type' => 'serial', 'image' => $file()], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_the_complaint_stays_as_the_office_wrote_it(): void
    {
        [$job] = $this->jobWithProduct();
        $this->inTenant($this->ctx['tenant'], fn () => $job->update(['complaint_details' => '<p><strong>Gas leak</strong> near valve</p>', 'priority' => 'low']));
        $html = $job->fresh()->complaint_details_html;
        $this->assertNotNull($html);

        // Older apps still send the complaint back, changed or not: it's left alone.
        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", ['complaint_details' => 'Now noisy too', 'priority' => 'high', 'serial_no' => 'SN-1'])->assertOk();
        $fresh = $job->fresh();
        $this->assertSame($html, $fresh->complaint_details_html);
        $this->assertSame('low', $fresh->priority);
        $this->assertDatabaseHas('customer_products', ['id' => $fresh->customer_product_id, 'serial_no' => 'SN-1']);
    }

    public function test_missing_model_and_category_can_be_filled_in(): void
    {
        $category = $this->inTenant($this->ctx['tenant'], fn () => ProductCategory::create(['name' => 'Split AC']));
        [$job, $product] = $this->jobWithProduct();

        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => 'NEW-1', 'category_id' => $category->id])->assertOk();

        $this->assertDatabaseHas('customer_products', ['id' => $product->id, 'model_no' => 'NEW-1', 'category_id' => $category->id]);
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'product_category' => 'Split AC']);
    }

    public function test_technician_adds_a_product_to_a_job_without_one(): void
    {
        $tenant = $this->ctx['tenant'];
        $customer = $this->makeCustomer($tenant);
        $job = $this->makeJob($tenant, $customer, $this->tech, ['customer_product_id' => null]);

        $this->actingAsUser($this->tech)->patchJson("/api/v1/jobs/{$job->id}/details", ['model_no' => 'ADD-1', 'serial_no' => 'SN-ADD', 'warranty_type' => 'out_of_warranty'])->assertOk();

        $productId = $job->fresh()->customer_product_id;
        $this->assertNotNull($productId);
        $this->assertDatabaseHas('customer_products', ['id' => $productId, 'customer_id' => $customer->id, 'model_no' => 'ADD-1', 'serial_no' => 'SN-ADD']);

        // A save with nothing in it adds nothing.
        $bare = $this->makeJob($tenant, $customer, $this->tech, ['customer_product_id' => null]);
        $this->patchJson("/api/v1/jobs/{$bare->id}/details", ['complaint_details' => 'Noise', 'serial_no' => null])->assertOk();
        $this->assertNull($bare->fresh()->customer_product_id);
    }

    public function test_job_updates_join_the_timeline_but_stay_out_of_the_customer_portal(): void
    {
        [$job] = $this->jobWithProduct();
        $this->actingAsUser($this->tech)->postJson("/api/v1/jobs/{$job->id}/notes", ['note' => 'Customer asked to come after 4 pm'])->assertCreated();
        $this->postJson("/api/v1/jobs/{$job->id}/notes", ['note' => ''])->assertStatus(422)->assertJsonValidationErrors('note');

        $this->getJson("/api/v1/jobs/{$job->id}")->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonFragment(['status' => 'note', 'remarks' => 'Customer asked to come after 4 pm']);

        $other = $this->makeUser($this->ctx['tenant'], Role::Technician);
        $this->forgetGuards()->actingAsUser($other)->postJson("/api/v1/jobs/{$job->id}/notes", ['note' => 'Not mine'])->assertNotFound();
    }

    public function test_customer_location_can_be_saved_from_the_job_without_a_visit(): void
    {
        [$job] = $this->jobWithProduct();
        $this->actingAsUser($this->tech)->postJson("/api/v1/jobs/{$job->id}/customer-location", ['lat' => 11.25, 'lng' => 75.78])->assertOk()->assertJsonPath('data.location_source', 'technician');

        $other = $this->makeUser($this->ctx['tenant'], Role::Technician);
        $this->forgetGuards()->actingAsUser($other)->postJson("/api/v1/jobs/{$job->id}/customer-location", ['lat' => 1, 'lng' => 2])->assertNotFound();

        $closed = $this->makeJob($this->ctx['tenant'], $job->customer, $this->tech, ['status' => 'completed']);
        $this->forgetGuards()->actingAsUser($this->tech)->postJson("/api/v1/jobs/{$closed->id}/customer-location", ['lat' => 1, 'lng' => 2])->assertStatus(422);
    }

    public function test_cancel_request_from_the_job_needs_a_reason_and_waits_for_approval(): void
    {
        [$job] = $this->jobWithProduct();
        $this->actingAsUser($this->tech);
        $this->postJson("/api/v1/jobs/{$job->id}/cancel-request", ['reason' => 'no'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/jobs/{$job->id}/cancel-request", ['reason' => 'Customer sold the unit'])->assertOk()->assertJsonPath('data.status', 'cancel_requested');
        // Can't ask twice, and can't cancel it themselves.
        $this->postJson("/api/v1/jobs/{$job->id}/cancel-request", ['reason' => 'Customer sold the unit'])->assertStatus(422);
        $this->postJson("/api/v1/jobs/{$job->id}/cancel/approve", ['reason' => 'Self approve'])->assertForbidden();

        $this->forgetGuards()->actingAsUser($this->ctx['admin'])->postJson("/api/v1/jobs/{$job->id}/cancel/approve", ['reason' => 'Confirmed by phone'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_visit_being_cancelled_cannot_carry_parts_charges_or_payment(): void
    {
        [$job] = $this->jobWithProduct();
        [$item, $branch] = $this->itemInBranch();
        $visit = $this->startVisit($job);
        $usage = $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $item->id, 'quantity' => 1, 'branch_id' => $branch->id])->assertCreated()->json('data.id');

        $reason = ['status' => 'cancelled', 'service_summary' => 'Customer does not want it'];
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'cancelled', 'service_summary' => 'short'])->assertStatus(422)->assertJsonValidationErrors('service_summary');
        $this->postJson("/api/v1/visits/{$visit}/complete", $reason)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/visits/{$visit}/spares/{$usage}")->assertOk();
        $this->postJson("/api/v1/visits/{$visit}/complete", $reason + ['labour_charge' => 10000, 'payment_method' => 'cash', 'amount_collected' => 10000])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/visits/{$visit}/complete", $reason)->assertOk();
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'status' => 'cancel_requested']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_technician_chooses_gst_or_non_gst_when_closing_the_visit(): void
    {
        $tenant = $this->ctx['tenant'];
        $tenant->update(['gstin' => '32AAAAA0000A1Z5', 'state' => 'Kerala']);
        [$job] = $this->jobWithProduct();
        $visit = $this->startVisit($job);
        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 118000, 'payment_method' => 'credit', 'is_gst' => false,
        ])->assertOk()->assertJsonPath('data.invoice.is_gst', false)->assertJsonPath('data.invoice.cgst_amount', 0);

        // The bill exists now, perhaps with the customer: a later visit can't change its type.
        $this->allowResume($job->id);
        $second = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $this->postJson("/api/v1/visits/{$second}/complete", [
            'status' => 'pending', 'service_summary' => 'Again', 'labour_charge' => 0, 'payment_method' => 'credit', 'is_gst' => true,
        ])->assertOk()->assertJsonPath('data.invoice.is_gst', false);

        // A new bill as GST: same price, tax split out.
        [$gstJob] = $this->jobWithProduct();
        $gstVisit = $this->postJson("/api/v1/jobs/{$gstJob->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $this->postJson("/api/v1/visits/{$gstVisit}/complete", [
            'status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 118000, 'payment_method' => 'credit', 'is_gst' => true,
        ])->assertOk()->assertJsonPath('data.invoice.is_gst', true)->assertJsonPath('data.invoice.taxable_amount', 100000)->assertJsonPath('data.invoice.total_amount', 118000);

        // Without a GSTIN there is no GST bill to choose.
        $tenant->update(['gstin' => null]);
        [$plainJob] = $this->jobWithProduct();
        $plainVisit = $this->postJson("/api/v1/jobs/{$plainJob->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $this->postJson("/api/v1/visits/{$plainVisit}/complete", [
            'status' => 'pending', 'service_summary' => 'Third', 'labour_charge' => 100, 'payment_method' => 'credit', 'is_gst' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_gst');
    }

    public function test_allocated_parts_leave_the_branch_and_are_tracked_on_the_job_invoice_and_collection(): void
    {
        [$job] = $this->jobWithProduct();
        [$item, $branch] = $this->itemInBranch(10);

        $this->allocate($item, $branch, 3)->assertCreated();
        $this->assertDatabaseHas('inventory_stock', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity_available' => 7]);
        $this->assertDatabaseHas('inventory_transactions', ['item_id' => $item->id, 'type' => 'allocated', 'quantity' => -3, 'reference_type' => 'technician', 'reference_id' => $this->tech->id]);

        // More than the branch has, or fractions of a counted part, are refused.
        $this->allocate($item, $branch, 100)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->allocate($item, $branch, 1.5)->assertStatus(422)->assertJsonValidationErrors('quantity');

        $visit = $this->startVisit($job);
        $this->getJson('/api/v1/my/parts')->assertOk()->assertJsonPath('data.stock.0.item.id', $item->id)->assertJsonPath('data.stock.0.quantity', 3);

        // Used from the technician's own stock: the branch doesn't move again.
        $usage = $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $item->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.from_technician_stock', true)->json('data.id');
        $this->assertDatabaseHas('technician_stock', ['technician_id' => $this->tech->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertDatabaseHas('inventory_stock', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity_available' => 7]);
        $this->assertDatabaseHas('technician_stock_moves', ['item_id' => $item->id, 'type' => 'used', 'quantity' => -2, 'job_id' => $job->id, 'job_visit_id' => $visit]);

        // Taken off the visit: back with the technician, not the branch.
        $this->deleteJson("/api/v1/visits/{$visit}/spares/{$usage}")->assertOk();
        $this->assertDatabaseHas('technician_stock', ['technician_id' => $this->tech->id, 'item_id' => $item->id, 'quantity' => 3]);
        $this->assertDatabaseHas('inventory_stock', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity_available' => 7]);

        // Asking for more than they hold, from their own stock, is refused; the branch's stock can still be asked for.
        $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $item->id, 'quantity' => 4, 'source' => 'technician'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $item->id, 'quantity' => 1, 'source' => 'branch', 'branch_id' => $branch->id])->assertCreated()->assertJsonPath('data.from_technician_stock', false);
        $this->assertDatabaseHas('inventory_stock', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity_available' => 6]);
        $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $item->id, 'quantity' => 2])->assertCreated();

        // Closing the visit: the parts are on the invoice, and the money reaches the day's collection with the job's number.
        $result = $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'pending', 'service_summary' => 'Fixed', 'payment_method' => 'cash', 'amount_collected' => 150000,
        ])->assertOk();
        $this->assertSame(150000, $result->json('data.invoice.total_spare_charge'));
        $this->getJson("/api/v1/jobs/{$job->id}")->assertOk()->assertJsonPath('data.invoice.payments.0.amount', 150000);
        $this->getJson('/api/v1/accounts/cash-summary')->assertOk()->assertJsonPath('data.payments.0.call_id', $job->crm_call_id);
        $this->getJson('/api/v1/my/parts')->assertJsonPath('data.moves.0.job.crm_call_id', $job->crm_call_id);

        // The office sees the same trail and takes back what's unused.
        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->getJson('/api/v1/inventory/technician-stock?technician_id='.$this->tech->id)->assertOk()->assertJsonPath('data.0.quantity', 1);
        $this->getJson('/api/v1/inventory/technician-moves?type=used')->assertOk()->assertJsonPath('data.0.job.crm_call_id', $job->crm_call_id);
        $back = ['technician_id' => $this->tech->id, 'item_id' => $item->id, 'branch_id' => $branch->id];
        $this->postJson('/api/v1/inventory/technician-stock/return', $back + ['quantity' => 5])->assertStatus(422);
        $this->postJson('/api/v1/inventory/technician-stock/return', $back + ['quantity' => 1])->assertCreated();
        $this->assertDatabaseHas('inventory_stock', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity_available' => 7]);
        $this->inTenant($this->ctx['tenant'], fn () => $this->assertSame(0.0, TechnicianStock::where('technician_id', $this->tech->id)->first()->quantity));

        // Allocation is office work, and only a technician can receive it.
        $this->allocateAs($this->tech, $item, $branch)->assertForbidden();
        $this->postJson('/api/v1/inventory/technician-stock/allocate', ['technician_id' => $this->ctx['admin']->id, 'item_id' => $item->id, 'branch_id' => $branch->id, 'quantity' => 1])->assertStatus(422);
    }

    private function allocateAs(User $user, InventoryItem $item, Branch $branch)
    {
        return tap($this->forgetGuards()->actingAsUser($user)->postJson('/api/v1/inventory/technician-stock/allocate', [
            'technician_id' => $this->tech->id, 'item_id' => $item->id, 'branch_id' => $branch->id, 'quantity' => 1,
        ]), fn () => $this->forgetGuards()->actingAsUser($this->ctx['admin']));
    }
}
