# Changelog

## Unreleased

### Added
- **Customer signature on visits.** `POST /visits/{id}/signature` (PNG + `signer_name`; signing again replaces it). Stored as a `signature` job image; the visit records `signer_name` and `signed_at`. Printed on the invoice PDF. Company setting `jobs.require_signature` blocks completing a visit without one; exposed to apps as `tenant.require_signature`.
- `POST /auth/device/test` sends a test push to the signed-in user's phones.

### Changed
- **Photo uploads accept full-size camera photos** (up to 25 MB, 12000 px). Large or rotated images are re-encoded server-side to a 2000 px JPEG with EXIF orientation applied (`App\Support\ImageOptimizer`). Docker PHP/nginx upload limits raised to 30 / 32 MB. Signatures don't count towards the 30-photo limit.
- FCM messages use the app's `default` Android channel with sound; tokens reported `UNREGISTERED` are removed.
- `POST /auth/logout` accepts `device_token` so the phone stops receiving the signed-out user's notifications.

### Fixed
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
