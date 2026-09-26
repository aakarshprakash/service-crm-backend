<?php

/*
| Role → ability matrix (SRS §4). Every API route is guarded with `can:<ability>`;
| technicians and customers are additionally restricted to their own records in
| the controllers/policies.
*/

$staff = ['admin', 'coordinator', 'accountant'];

return [
    'platform.manage' => ['super_admin'],

    'dashboard.view' => ['admin', 'coordinator', 'accountant'],
    'users.view' => ['admin', 'coordinator'],
    'users.manage' => ['admin'],
    'settings.manage' => ['admin'],
    'master.view' => [...$staff, 'technician'],
    'master.manage' => ['admin'],

    'customers.view' => $staff, // technicians see customers only through their assigned jobs
    'customers.privacy' => ['admin'], // data export / anonymisation requests
    'customers.manage' => ['admin', 'coordinator'],

    'jobs.view' => [...$staff, 'technician'],
    'jobs.manage' => ['admin', 'coordinator'],
    'visits.execute' => ['technician'],
    'punch' => ['technician'],

    'inventory.view' => [...$staff, 'technician'],
    'inventory.manage' => ['admin', 'accountant'],

    'invoices.view' => [...$staff, 'technician'],
    'payments.record' => ['admin', 'accountant'], // office collections against an invoice

    'cash.submit' => ['technician', 'admin'],     // admin can force-close for an unavailable technician
    'cash.view' => ['admin', 'accountant', 'technician'],
    'cash.verify' => ['admin', 'accountant'],

    'reports.view' => ['admin', 'accountant', 'coordinator'],
    'reports.financial' => ['admin', 'accountant'],
    'notifications.logs' => ['admin'],
    'audit.view' => ['admin'],

    'portal' => ['customer'],
];
