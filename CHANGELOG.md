# Changelog

## Unreleased

### Added
- **SAPRE spare parts.** `brand_spares` (one row per part line Lloyd bills) and `spare_imports` (upload history). The status is worked out on every save: warranty `pending_inspection` → `pending_dispatch` → `pending_return` → `return_completed` / `replacement_completed`; OW `ow_billing_pending` (orange) → `payment_pending` (light blue) → `completed` (grey); `unsorted`; stock is `completed`. A warranty part Lloyd charges as OW (`ow_billed`) joins the OW path. `GET /spares` (filters `status` (comma list), `group`, `path`, `coverage`, `attention`, `search`, `from`, `to`, `ticket`), `GET /spares/summary`, `GET /spares/recent`, `GET/POST/PATCH/DELETE /spares/{id}`, `GET /spares/{id}/activity`, `POST /spares/bulk` (coverage, inspect, rma, fresh, send, received, ow_billed, ow_paid, company_cleared, delete; each applies only to parts at the right status), `POST /spares/import` (Lloyd's SAP export, the old colour-coded tracking sheet or the SAPRE template; adds new lines, updates existing ones from filled cells, never clears; lists refused rows; `dry_run=1` previews), `POST /spares/company-pending` (Lloyd's pending list; sent parts that drop off it are marked received), `GET /spares/export`, `GET /spares/template`, `GET /spares/imports`. Row colours of the old sheet set the OW stage. A part links to the job whose External CRM call ID is its EX CRM ID. Abilities `spares.view` / `spares.manage`. It never moves Inventory stock or the accounts.
- `GET /my/punch` (every staff member): on duty or not, since when, and the punch rules (`attendance`: geofence, office location and radius, location and punch-in requirements, auto punch-out time, shift). Used by the Servon Manager app's punch-in. The technician dashboard's `attendance` block now comes from the same `AttendanceService::rules()`.
- **Estimates.** `estimates` / `estimate_items` tables. `GET/POST /estimates`, `GET /estimates/summary`, `GET/PATCH/DELETE /estimates/{id}`, `POST /estimates/{id}/status` (draft, sent, approved, rejected), `POST /estimates/{id}/duplicate`, `GET /estimates/{id}/pdf`, and the public `GET /estimate/{token}/pdf` behind the shared link. Abilities `estimates.view` / `estimates.manage`. GST is split out the same way as on invoices. An estimate never moves stock or money.
- **Customer feedback on jobs.** `service_jobs.feedback_taken_at / feedback_taken_by / feedback_note`. The technician enters the Customer Satisfaction Code on site during the visit; the code by itself does not mark feedback collected. The office records the customer's feedback and marks it collected with `POST /jobs/{id}/feedback` (office only, `jobs.manage`); a rating on the portal counts too. Jobs carry `feedback_pending`; `GET /jobs?quick=feedback_pending`. A completed job stays in the technician's list until the office collects feedback (`jobs.tech_keep_until_feedback`, on by default). Jobs completed before this release count as taken. The web job detail shows the satisfaction code and remarks as an always-editable card; the Jobs list badges feedback as pending (amber) or collected (green, once both the code and the remarks are in).
- `GET /jobs?warranty=under|out`.
- `GET /jobs/{id}/photos.zip`: the job's photos in a folder per section (office, and the assigned technician).
- **Permanent delete of test entries.** `GET/PUT /hard-delete` (the admin's password switches it on for 24 hours), `GET /hard-delete/{type}/{id}` (what would be removed) and `DELETE /hard-delete/{type}/{id}` with the record's number typed back, for `job`, `customer`, `invoice` (walk-in bills), `payment`, `expense` and `income`. Used parts go back to stock, a receipt already in a technician's cash close is taken out of that close, files are removed, and every deletion is written to the audit log. Ability `records.hard_delete` (admin).
- **Trips.** `GET /hr/trips` (per technician per day, with totals) and `GET /hr/trips/{userId}?date=` (stop by stop), built from punches and visits. Distance is the straight line between stops.
- The register (`GET /books/register`) lists cash technicians handed to the office each day (`handovers`) and, for a period that reaches today, where the cash is now (`cash_now`: office drawer and each technician).
- Lookups carry `tech_app.show_leave` (Settings; off by default) and `hard_delete`.

### Changed
- **Simpler GST on the estimate PDF.** The "GST summary" table (HSN, rate, CGST, SGST per rate) and the CGST/SGST lines are gone. A GST estimate's totals now read Total, Total GST and the estimated total including GST. The GST amounts are still stored with the estimate and shown in the web app.
- **A job left pending keeps its uploads.** When a new visit starts on a job that has earlier visits (the same technician resuming, or another one after the office reassigns it), the earlier photos (not signatures) and voice notes move into the new visit and the service summary is pre-filled with the last one, so the technician can edit, replace, delete and save them. Parts, charges and payments stay on the visit that billed them.
- Office forms no longer require a purchase date for a unit marked in warranty (the technician app still asks for it).
- A unit with no warranty expiry date counts as covered when it is marked in warranty, extended or AMC.
- **Faster accounts pages.** Cash and bank balances are added up by the database instead of reading every entry since the books opened; cash held by technicians is worked out for everyone in four queries instead of several per technician. New indexes on `payments (tenant_id, status, paid_at)` and `job_visits (tenant_id, technician_id, start_time)`.
- Signed links to photos, voice notes and receipts stay the same for up to an hour, so phones and browsers cache the file instead of downloading it again on every refresh.
- **Servon Manager app add-on.** New plan feature `manager_app`. `POST /auth/token` takes `app: "manager"`: only admins, coordinators and accountants of a company whose plan has the feature can sign in (technicians are pointed to their app). Manager tokens carry the `app:manager` ability and are revoked with a 401 (`code: manager_app_unavailable`) once the plan loses the feature. The profile exposes `tenant.manager_app`; `/version` returns `min_manager_version`.
- Office push notifications: a customer raising a request on the portal (`new_complaint`, admin + coordinator), a technician submitting a cash close (`cash_close_submitted`, admin + accountant, with any shortfall), and a 9:30 am `jobs_attention` summary of overdue and unassigned jobs (admin + coordinator, scheduler).
- `GET /jobs?overdue=1` (open / pending with a visit date before today, the dashboard's "overdue" rule).
- **Second Firebase project for Servon Manager.** Device tokens record the app that registered them (`device_tokens.app`, from the session's `app:manager` ability). Manager tokens are sent with `FCM_MANAGER_CREDENTIALS` (default `storage/app/private/fcm-manager.json`), falling back to `FCM_CREDENTIALS` when that file is missing. FCM OAuth tokens are cached per service account.
- **Customer signature on visits.** `POST /visits/{id}/signature` (PNG + `signer_name`; signing again replaces it). Stored as a `signature` job image; the visit records `signer_name` and `signed_at`. Printed on the invoice PDF. Company setting `jobs.require_signature` blocks completing a visit without one; exposed to apps as `tenant.require_signature`.
- `POST /auth/device/test` sends a test push to the signed-in user's phones.
- **Expense claims from the field.** `/my/expenses` (submit, receipt photo, withdraw) and `/expense-claims` (office approve / reject). Approval books a real Expense; claims paid from collected cash are deducted at the technician's daily cash close (`cash_closes.total_expenses`), and rejecting one in a submitted close adds it back.
- **Voice notes on visits.** `POST/DELETE /visits/{id}/voice-notes` (m4a / aac / mp3 / webm / wav, 20 MB), signed playback URL, shown on the job.
- **Customer location sharing.** `POST /jobs/{id}/location` (paste a WhatsApp / Google Maps link, short links expanded safely), `POST /jobs/{id}/location-request` (link sent by SMS / WhatsApp, template `location_request`), public `/share-location/{token}`, and `POST /visits/{id}/customer-location` for the technician on site. The assigned technician is notified.

### Changed
- **App sign-ins expire after 60 days without use** (`SANCTUM_IDLE_DAYS`, 0 = never). Idle tokens are refused with 401 and deleted by the daily `prune-idle-tokens` task.
- `GET /expense-claims?status=` accepts several statuses (`approved,rejected`).
- **Photo uploads accept full-size camera photos** (up to 25 MB, 12000 px). Large or rotated images are re-encoded server-side to a 2000 px JPEG with EXIF orientation applied (`App\Support\ImageOptimizer`). Docker PHP/nginx upload limits raised to 30 / 32 MB. Signatures don't count towards the 30-photo limit.
- FCM messages use the app's `default` Android channel with sound; tokens reported `UNREGISTERED` are removed.
- UPI reference is optional for UPI payments (cheque number and bank UTR are still required).
- `POST /auth/logout` accepts `device_token` so the phone stops receiving the signed-out user's notifications.

### Fixed
- Technicians could read purchase cost (`avg_unit_cost`) and `stock_value` from `GET /inventory/stock`; both are now returned to office roles only.
- 8 MB+ camera photos were rejected with "The image field must not be greater than 8192 kilobytes."

## 2.1.0 — 2026-10-06

### Added
- **UPI collection.** UPI accounts in master data (`/master/upi-accounts`, one default, optional per branch). Invoice API returns a `upi` deep link for the balance; invoice PDFs and the public pay page show a “scan to pay” QR.
- **Accounts.** Cash book and bank book with running balances (`/books/ledger`), opening balances (`PUT /books/opening`), cash deposits / withdrawals (`/books/transfers`), receivables with aging (`/books/receivables`), accounts overview (`/books/overview`), profit & loss vs previous period (`/books/profit-loss`). Receipts register (`/payments`) now has search, local-date filters and totals by method.
- **HR.** Geo-fenced punching for all staff (branch lat/lng/radius; company setting off / flag / enforce), attendance register and daily view, manual attendance corrections, holidays and leave types in master data, leave apply / approve / cancel with balances, employee HR profiles with salary structure, monthly payroll from attendance (draft → finalized → paid, salaries posted as expenses), payslip PDFs. Self-service under `/my/*`.
- **Reports:** Cash Book, Bank Book, Receipts Register, Attendance Summary, Leave Register, Payroll Summary.
- `GET /api/v1/version` with `min_mobile_version` for the technician app's update check.
- `V21DemoSeeder` (also run by `DemoSeeder`).

### Changed
- `POST /punch` is open to office staff too and records location accuracy, branch distance and whether the punch was inside the geofence.

### Fixed
- Expenses could not be dated "today" between midnight and 05:30 IST (date check used UTC).
- `GET /expenses` overwrote the pager's `meta.total` with the money total; the sum is now `meta.total_amount`.
