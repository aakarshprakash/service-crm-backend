<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Holds the tenant for the current request / queued job.
 *
 * The tenant is resolved from the authenticated user only; it is never read from
 * client input. With no tenant set and no explicit bypass, tenant-scoped queries
 * return nothing (fail closed).
 */
class TenantContext
{
    private ?int $tenantId = null;

    private ?Tenant $tenant = null;

    private bool $bypass = false;

    public function set(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->tenant = null;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenantId && ! $this->tenant) {
            $this->tenant = Tenant::find($this->tenantId);
        }

        return $this->tenant;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function bypassing(): bool
    {
        return $this->bypass;
    }

    /** Run a callback with tenant scoping disabled (super admin / system tasks). */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;
        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    /** Run a callback as a specific tenant (queued jobs, webhooks). */
    public function runAs(int $tenantId, callable $callback): mixed
    {
        $previousId = $this->tenantId;
        $previousBypass = $this->bypass;
        $this->set($tenantId);
        $this->bypass = false;
        try {
            return $callback();
        } finally {
            $this->set($previousId);
            $this->bypass = $previousBypass;
        }
    }

    public function reset(): void
    {
        $this->tenantId = null;
        $this->tenant = null;
        $this->bypass = false;
    }
}
