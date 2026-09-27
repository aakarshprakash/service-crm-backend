<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->with('branch:id,name')
            ->withCount('jobs')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim($request->string('search'));
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('alt_phone', 'like', $like)
                    ->orWhere('crm_id', $term)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('products', fn ($p) => $p->where('serial_no', $term)->orWhere('outdoor_serial_no', $term)));
            })
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + $this->productRules('products.*.'));

        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::create(Arr::except($data, ['products']));
            foreach ($data['products'] ?? [] as $product) {
                $customer->products()->create($product);
            }

            return $customer;
        });

        return $this->created($customer->load(['products.product.brand', 'branch:id,name']), 'Customer created.');
    }

    /** FR-4.4: full profile with products and complaint history. PII access is logged. */
    public function show(int $id): JsonResponse
    {
        $customer = Customer::with([
            'branch:id,name',
            'products.product.brand:id,name', 'products.product.category:id,name', 'products.dealer:id,name',
        ])->findOrFail($id);

        $jobs = $customer->jobs()
            ->with(['technician:id,name', 'complaintType:id,name', 'customerProduct.product:id,model_name', 'invoice:id,job_id,invoice_number,total_amount,balance_amount,payment_status'])
            ->latest('id')->limit(100)->get();

        $outstanding = (int) $customer->invoices()->sum('balance_amount');
        Audit::log('customer.viewed', $customer);

        return $this->ok(['customer' => $customer, 'jobs' => $jobs, 'outstanding' => $outstanding]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->validate($this->rules($customer)));

        return $this->ok($customer->load('branch:id,name'), 'Customer updated.');
    }

    public function storeProduct(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $product = $customer->products()->create($request->validate($this->productRules()));

        return $this->created($product->load(['product.brand:id,name', 'dealer:id,name']), 'Product added.');
    }

    public function updateProduct(Request $request, int $id, int $productId): JsonResponse
    {
        $product = CustomerProduct::where('customer_id', $id)->findOrFail($productId);
        $product->update($request->validate($this->productRules()));

        return $this->ok($product->load(['product.brand:id,name', 'dealer:id,name']), 'Product updated.');
    }

    /** Data-subject export (NFR Data Privacy). */
    public function export(int $id): JsonResponse
    {
        $customer = Customer::with(['products.product', 'jobs.visits', 'invoices.payments'])->findOrFail($id);
        Audit::log('customer.exported', $customer);

        return $this->ok($customer);
    }

    /** Data-subject deletion: anonymise PII, keep financial / job history intact. */
    public function anonymize(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        DB::transaction(function () use ($customer) {
            $customer->forceFill([
                'name' => 'Deleted customer #'.$customer->id,
                'phone' => '0000000000',
                'alt_phone' => null,
                'email' => null,
                'address' => null,
                'city' => null,
                'pincode' => null,
                'lat' => null,
                'lng' => null,
            ])->save();
            User::inTenant()->where('customer_id', $customer->id)->update(['status' => 'inactive', 'phone' => null, 'name' => 'Deleted customer']);
            $customer->delete();
        });
        Audit::log('customer.anonymized', $customer);

        return $this->ok(null, 'Customer personal data removed.');
    }

    private function rules(?Customer $customer = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $req = $customer ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'phone' => [$req, 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'alt_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'crm_id' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'crm_id')->where('tenant_id', $tenantId)->ignore($customer?->id)],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    private function productRules(string $prefix = ''): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            $prefix.'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            $prefix.'serial_no' => ['nullable', 'string', 'max:100'],
            $prefix.'outdoor_serial_no' => ['nullable', 'string', 'max:100'],
            $prefix.'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            $prefix.'warranty_type' => ['nullable', Rule::in(['in_warranty', 'extended', 'amc', 'out_of_warranty'])],
            $prefix.'warranty_expiry' => ['nullable', 'date'],
            $prefix.'dealer_id' => ['nullable', 'integer', Rule::exists('dealers', 'id')->where('tenant_id', $tenantId)],
        ] + ($prefix ? ['products' => ['nullable', 'array', 'max:20']] : []);
    }
}
