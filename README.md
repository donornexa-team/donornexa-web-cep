# DonorNexa

*Connecting Donors. Bridging Lives. Saving Hope.*

Emergency blood-donor coordination portal. Hospitals raise blood requests, the system matches the best eligible donors, donors accept or decline, and the hospital records the donation. Web Engineering CEP, plain PHP 8 + MySQL/MariaDB (no framework).

## Features
- **Donors:** register, edit profile and photo, availability toggle, "eligible again on", inbox of matched requests (accept/decline), donation history and badges.
- **Hospitals:** register with licence upload (admin verifies), raise requests (max 3 open critical), see matched donors, phone number shown only after a donor accepts, mark donation, close/cancel (cancel lowers trust score), blood inventory.
- **Admin:** dashboard with charts, verify/reject hospitals, block/unblock users, audit log, CSV reports, live donor search.
- **Public:** landing page and live emergencies board.
- **Matching:** blood-group compatibility, eligibility (age, weight, cool-down, availability), ranking by city, recency and history.
- **Security:** prepared statements, CSRF token on every POST, password hashing, role checks on every action, login lockout (5 failures per email / 20 per IP, 15 min), upload checks, audit log of important actions.
- **All users:** login with "keep me logged in" (signed 30-day cookie, cleared on logout).

## Setup (XAMPP)
1. Use PHP 8.0+ and set the timezone to `Asia/Karachi`.
2. Clone into `htdocs`: `git clone <repo-url> DonorNexa`
3. Copy `config/db.example.php` to `config/db.local.php` and set your credentials. **The default port is 3307; add `"port" => 3306` if your MySQL uses 3306.**
4. In phpMyAdmin create database `donornexa` and import, in order: `sql/schema.sql`, `sql/seed0_reference.sql`, `sql/seed_1_accounts.sql`, `sql/seed_2_requests.sql`, `sql/seed_3_inventory.sql`.
5. Open http://localhost/DonorNexa/public/

## Test logins
All passwords: `Password@123`

| Role | Email |
|---|---|
| Admin | `admin@donornexa.test` |
| Hospital (verified) | `hospital1@donornexa.test` ... `hospital4@donornexa.test` |
| Hospital (pending) | `hospital5@donornexa.test` |
| Donor | `danish.siddiqui@mail.test` (others: `SELECT email FROM users WHERE role='donor'`) |

## Warning
> These accounts are demo seed data for local testing only. Change or remove them before deploying anywhere public.

## Try the full flow
1. Log in as `hospital1@donornexa.test`, open **New Request**, raise an O+ request.
2. The detail page lists the matched donors; log in as one of them and **Accept** under *Requests for Me*.
3. Back as the hospital, the donor's phone is now visible; click **Mark donated** (inventory +1, request fulfilled).
4. Log in as admin and open **Audit Log** to see every step.

## Tests
Run from the project folder (database tests need the seed data and skip otherwise):

```
php tests/matching_test.php      php tests/request_test.php
php tests/response_test.php      php tests/concurrency_test.php
php tests/profile_test.php       php tests/inventory_test.php
php tests/dashboard_test.php     php tests/listquery_test.php
php tests/csv_test.php           php tests/donor_search_test.php
```

`concurrency_test.php` starts 5 parallel PHP processes accepting the same 1-unit request; exactly one wins.

## Project layout
```
public/      front controller (index.php), assets, uploads
config/      settings, bootstrap, route files (one per slice)
src/         Core, Controllers, Models, Services (rules), Repositories (SQL), Exceptions
templates/   PHP views      api/  JSON endpoints      sql/  schema + seeds
tests/       CLI test scripts     docs/  ERD and database notes
```

