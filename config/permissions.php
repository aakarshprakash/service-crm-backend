<?php

/*
| Role → ability matrix (SRS §4). Every API route is guarded with `can:<ability>`;
| technicians and customers are additionally restricted to their own records in
| the controllers/policies.
|
| Office roles:
|   admin            – everything in the company
|   coordinator      – customers, jobs, team, attendance & leave
|   accountant       – accounts, cash, payments, expenses, stock, payroll
|   head_engineer    – supervises technicians: all jobs, team, attendance & leave, stock view
|   hr_executive     – employees, attendance, leave, payroll
|   store_keeper     – stock and assets
|   service_tracker  – call centre: customers and jobs
*/

$staff = ['admin', 'coordinator', 'accountant'];
$office = [...$staff, 'head_engineer', 'hr_executive', 'store_keeper', 'service_tracker'];

return [
    'platform.manage' => ['super_admin'],

    'dashboard.view' => $office,
    'users.view' => ['admin', 'coordinator', 'head_engineer', 'hr_executive'],
    'users.manage' => ['admin'],
    'settings.manage' => ['admin'],
    'master.view' => [...$office, 'technician'],
    'master.manage' => ['admin'],
    'master.quick_add' => ['admin', 'coordinator', 'head_engineer', 'service_tracker'], // brand / model from the job form

    'customers.view' => [...$staff, 'head_engineer', 'service_tracker'], // technicians see customers only through their assigned jobs
    'customers.privacy' => ['admin'], // data export / anonymisation requests
    'customers.manage' => ['admin', 'coordinator', 'head_engineer', 'service_tracker'],

    'jobs.view' => [...$staff, 'head_engineer', 'service_tracker', 'store_keeper', 'technician'],
    'jobs.manage' => ['admin', 'coordinator', 'head_engineer', 'service_tracker'],
    'jobs.approve_cancel' => ['admin', 'coordinator', 'head_engineer'], // decides a technician's request to cancel
    'visits.execute' => ['technician'],
    'punch' => [...$office, 'technician'], // v2.1: office staff punch too (geo-fenced attendance)

    // HR (v2.1)
    'self.service' => [...$office, 'technician'], // own attendance, leave, payslips
    'hr.view' => ['admin', 'coordinator', 'head_engineer', 'hr_executive'], // attendance register / who's in today
    'hr.manage' => ['admin', 'hr_executive'],                                // correct attendance
    'leave.approve' => ['admin', 'coordinator', 'head_engineer', 'hr_executive'],
    'payroll.manage' => ['admin', 'accountant', 'hr_executive'],             // salaries, employee HR details, payroll

    'billing.walkin' => $staff,
    'expenses.manage' => ['admin', 'accountant'],
    'accounts.view' => ['admin', 'accountant'],

    'assets.view' => [...$staff, 'head_engineer', 'store_keeper'],
    'assets.manage' => ['admin', 'coordinator', 'store_keeper'],

    'inventory.view' => [...$staff, 'head_engineer', 'store_keeper', 'technician'],
    'inventory.manage' => ['admin', 'accountant', 'store_keeper'],

    'invoices.view' => [...$staff, 'head_engineer', 'service_tracker', 'technician'],
    'payments.record' => ['admin', 'accountant'], // office collections against an invoice
    'invoices.change_bill_type' => ['admin', 'accountant'], // GST / non-GST on an existing bill

    'cash.submit' => ['technician', 'admin'],     // admin can force-close for an unavailable technician
    'cash.view' => ['admin', 'accountant', 'technician'],
    'cash.verify' => ['admin', 'accountant'],

    'reports.view' => ['admin', 'accountant', 'coordinator', 'head_engineer', 'service_tracker'],
    'reports.financial' => ['admin', 'accountant'],
    'notifications.logs' => ['admin'],
    'audit.view' => ['admin'],

    'portal' => ['customer'],
];
