# DonorNexa

Connecting Donors. Bridging Lives. Saving Hope.

Smart emergency blood-donor coordination portal (PHP + MySQL), Web Engineering CEP.

## Local setup
1. Install XAMPP (same PHP version for all members), set timezone to Asia/Karachi.
2. Clone into htdocs: `git clone <repo-url> DonorNexa`
3. Copy `config/db.example.php` to `config/db.local.php` and edit credentials.
4. Import `sql/schema.sql` then `sql/seed0_reference.sql` in phpMyAdmin.
5. Open http://localhost/DonorNexa/public/

## Database
- The schema file is loacted at `sql/`, along with the reference seed data.
- The ERD is provided in `donornexa_erd.png`.
