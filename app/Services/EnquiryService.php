<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Estimate;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Sequence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Enquiries: logging them, the follow-up trail, and turning one into a customer, a job or a quotation. */
class EnquiryService
{
    public const STATUS_WORDS = ['new' => 'New', 'follow_up' => 'Following up', 'converted' => 'Converted', 'lost' => 'Lost'];

    public function create(array $data, User $by): Enquiry
    {
        return DB::transaction(function () use ($data, $by) {
            $tenant = app(TenantContext::class)->tenant();
            $enquiry = Enquiry::create($data + [
                'enquiry_number' => $this->number($tenant->id, (string) $tenant->setting('enquiry_prefix', 'ENQ')),
                'customer_id' => $data['customer_id'] ?? $this->customerWithPhone($data['phone'])?->id,
                'status' => 'new',
                'created_by' => $by->id,
            ]);
            $this->log($enquiry, $by, 'status', 'Enquiry logged.');

            return $enquiry;
        });
    }

    public function update(Enquiry $enquiry, array $data, User $by): Enquiry
    {
        if (isset($data['phone']) && $data['phone'] !== $enquiry->phone && ! $enquiry->customer_id) {
            $data['customer_id'] = $this->customerWithPhone($data['phone'])?->id;
        }
        $enquiry->update($data);

        return $enquiry;
    }

    /**
     * A follow-up: what was said, and when to call again. A new enquiry that has been followed up is no
     * longer new. A closed one stays closed; reopen it first.
     */
    public function addNote(Enquiry $enquiry, User $by, string $note, ?string $followUpAt, bool $setFollowUp): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $by, $note, $followUpAt, $setFollowUp) {
            $this->log($enquiry, $by, 'note', $note);
            if ($enquiry->isOpen()) {
                $enquiry->status = 'follow_up';
                if ($setFollowUp) {
                    $enquiry->follow_up_at = $followUpAt;
                }
                $enquiry->save();
            }

            return $enquiry;
        });
    }

    public function markLost(Enquiry $enquiry, User $by, string $reason): Enquiry
    {
        $this->assertOpen($enquiry);

        return $this->close($enquiry, $by, 'lost', ['lost_reason' => $reason], "Marked lost: {$reason}");
    }

    /** Converted without a job or quotation here, e.g. the customer bought over the counter. */
    public function markConverted(Enquiry $enquiry, User $by, ?string $note = null): Enquiry
    {
        $this->assertOpen($enquiry);

        return $this->close($enquiry, $by, 'converted', [], 'Marked converted'.($note ? ": {$note}" : '.'));
    }

    public function reopen(Enquiry $enquiry, User $by): Enquiry
    {
        if ($enquiry->isOpen()) {
            return $enquiry;
        }
        $enquiry->forceFill(['status' => 'follow_up', 'lost_reason' => null, 'closed_at' => null])->save();
        $this->log($enquiry, $by, 'status', 'Reopened.');

        return $enquiry;
    }

    /**
     * The customer this enquiry is for: the one already linked, one with the same phone number, or a new
     * customer made from the enquiry's details. Used before opening the job or quotation form.
     */
    public function customerFor(Enquiry $enquiry, User $by): Customer
    {
        return DB::transaction(function () use ($enquiry, $by) {
            $customer = ($enquiry->customer_id ? Customer::find($enquiry->customer_id) : null) ?? $this->customerWithPhone($enquiry->phone);
            if (! $customer) {
                if (! preg_match('/^\+?[0-9]{10,15}$/', $enquiry->phone)) {
                    throw ValidationException::withMessages(['phone' => 'Correct the phone number first: it needs 10 to 15 digits to add the customer.']);
                }
                $customer = Customer::create([
                    'name' => $enquiry->name, 'phone' => $enquiry->phone, 'email' => $enquiry->email, 'address' => $enquiry->address,
                    'city' => $enquiry->city, 'pincode' => $enquiry->pincode, 'branch_id' => $enquiry->branch_id,
                ]);
                $this->log($enquiry, $by, 'status', "Added as a customer ({$customer->name}).");
            }
            if ($enquiry->customer_id !== $customer->id) {
                $enquiry->forceFill(['customer_id' => $customer->id])->save();
            }

            return $customer;
        });
    }

    /** A job was booked from the enquiry: it is converted. */
    public function jobCreated(int $enquiryId, ServiceJob $job, User $by): void
    {
        $enquiry = Enquiry::find($enquiryId);
        if (! $enquiry) {
            return;
        }
        $enquiry->forceFill(['job_id' => $job->id, 'customer_id' => $enquiry->customer_id ?? $job->customer_id])->save();
        if ($enquiry->isOpen()) {
            $this->close($enquiry, $by, 'converted', [], "Job {$job->crm_call_id} booked.");
        } else {
            $this->log($enquiry, $by, 'status', "Job {$job->crm_call_id} booked.");
        }
    }

    /** A quotation was made from the enquiry. It stays open until the customer accepts it. */
    public function estimateCreated(int $enquiryId, Estimate $estimate, User $by): void
    {
        $enquiry = Enquiry::find($enquiryId);
        if (! $enquiry) {
            return;
        }
        $enquiry->forceFill(['estimate_id' => $estimate->id, 'customer_id' => $enquiry->customer_id ?? $estimate->customer_id])->save();
        if ($enquiry->status === 'new') {
            $enquiry->forceFill(['status' => 'follow_up'])->save();
        }
        $this->log($enquiry, $by, 'status', "Quotation {$estimate->estimate_number} made.");
    }

    /** The customer answered the quotation: accepted converts the enquiry, rejected loses it. */
    public function estimateDecided(Estimate $estimate, ?User $by): void
    {
        $enquiry = Enquiry::where('estimate_id', $estimate->id)->first();
        if (! $enquiry || ! $enquiry->isOpen() || ! in_array($estimate->status, ['approved', 'rejected'], true)) {
            return;
        }
        $approved = $estimate->status === 'approved';
        $this->close($enquiry, $by, $approved ? 'converted' : 'lost', $approved ? [] : ['lost_reason' => 'Quotation rejected'],
            "Quotation {$estimate->estimate_number} ".($approved ? 'accepted.' : 'rejected.'));
    }

    public function log(Enquiry $enquiry, ?User $by, string $kind, string $note): void
    {
        $enquiry->notes()->create(['user_id' => $by?->id, 'kind' => $kind, 'note' => $note]);
    }

    private function close(Enquiry $enquiry, ?User $by, string $status, array $extra, string $note): Enquiry
    {
        $enquiry->forceFill(['status' => $status, 'closed_at' => now(), 'follow_up_at' => null] + $extra)->save();
        $this->log($enquiry, $by, 'status', $note);

        return $enquiry;
    }

    private function assertOpen(Enquiry $enquiry): void
    {
        if (! $enquiry->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This enquiry is already closed. Reopen it first.']);
        }
    }

    private function customerWithPhone(?string $phone): ?Customer
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : Customer::where(fn ($q) => $q->where('phone', $phone)->orWhere('alt_phone', $phone))->orderBy('id')->first();
    }

    private function number(int $tenantId, string $prefix): string
    {
        do {
            $number = Sequence::formatted($tenantId, 'enquiry', $prefix ?: 'ENQ');
        } while (Enquiry::where('enquiry_number', $number)->exists());

        return $number;
    }
}
