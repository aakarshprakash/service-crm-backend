<?php

use App\Enums\Role;
use App\Models\OtpCode;
use App\Models\ReportExport;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Scheduled tasks – run `php artisan schedule:work` (or cron `schedule:run`).
*/

// FR-12.1: morning push reminder to technicians for today's scheduled visits.
Schedule::call(function (NotificationService $notifications, TenantContext $context) {
    Tenant::whereIn('status', ['trial', 'active'])->each(function (Tenant $tenant) use ($notifications, $context) {
        $context->runAs($tenant->id, function () use ($tenant, $notifications) {
            $start = now($tenant->timezone)->startOfDay()->utc();
            $end = now($tenant->timezone)->endOfDay()->utc();
            ServiceJob::whereIn('status', ['open', 'pending'])->whereBetween('scheduled_at', [$start, $end])
                ->whereNotNull('assigned_technician_id')
                ->selectRaw('assigned_technician_id, COUNT(*) as total')->groupBy('assigned_technician_id')
                ->get()->each(function ($row) use ($notifications) {
                    if ($tech = User::inTenant()->where('role', Role::Technician->value)->where('status', 'active')->find($row->assigned_technician_id)) {
                        $notifications->notifyUser($tech, 'visit_reminder', 'Today\'s visits', "You have {$row->total} visit(s) scheduled today.");
                    }
                });
        });
    });
})->name('visit-reminders')->dailyAt('08:00')->timezone('Asia/Kolkata')->withoutOverlapping();

// Morning heads-up for the people who dispatch: visits that slipped past their date and jobs nobody has.
Schedule::call(function (NotificationService $notifications, TenantContext $context) {
    Tenant::whereIn('status', ['trial', 'active'])->each(function (Tenant $tenant) use ($notifications, $context) {
        $context->runAs($tenant->id, function () use ($tenant, $notifications) {
            $todayStart = now($tenant->timezone)->startOfDay()->utc();
            $overdue = ServiceJob::whereIn('status', ['open', 'pending'])->where('scheduled_at', '<', $todayStart)->count();
            $unassigned = ServiceJob::whereIn('status', ['open', 'pending'])->whereNull('assigned_technician_id')->count();
            if (! $overdue && ! $unassigned) {
                return;
            }
            $parts = array_filter([$overdue ? "{$overdue} overdue" : null, $unassigned ? "{$unassigned} unassigned" : null]);
            $notifications->notifyRoles(['admin', 'coordinator'], 'jobs_attention', 'Jobs needing attention', 'Today: '.implode(', ', $parts).' job(s).', ['screen' => 'jobs']);
        });
    });
})->name('jobs-attention')->dailyAt('09:30')->timezone('Asia/Kolkata')->withoutOverlapping();

// Housekeeping.
Schedule::call(fn () => OtpCode::where('expires_at', '<', now()->subDay())->delete())->name('prune-otps')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->weekly();
Schedule::call(function () {
    ReportExport::where('created_at', '<', now()->subDays(7))->get()->each(function (ReportExport $export) {
        if ($export->file_path) {
            Storage::disk('private')->delete($export->file_path);
        }
        $export->delete();
    });
})->name('prune-exports')->daily();
