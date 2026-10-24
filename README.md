# Demo Coaches: Bus Reservation System (portfolio demo)

A PHP + MySQL bus seat reservation system with a public booking page, a booking-clerk
dashboard and an admin panel. **Demo Coaches is a fictional company**; no real bookings are taken.

## Features
- Public booking: search trips, pick a seat on a live seat map, reserve (pay at terminal / call office)
- Transaction-safe seat booking (no double booking), per-IP rate limits, unpaid holds auto-released
- Staff portal with Admin and Clerk roles, forced password change, DB-backed login lockout, CSRF-protected logout
- Contact form with honeypot and rate limiting

## Tech
PHP 8 (mysqli, prepared statements), MySQL/MariaDB, vanilla JavaScript, HTML/CSS.

## Run it locally (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\demo_coaches\`.
2. Start Apache and MySQL, open phpMyAdmin and import `db.sql`.
3. Visit `http://localhost/demo_coaches/` (staff login: `admin_login.php`; both starter accounts must set a new password at first login).

## Deploy to shared hosting
1. Create a MySQL database in the host's control panel and import `db.sql` (delete its first two lines, `CREATE DATABASE` / `USE`).
2. Copy `config.sample.php` to `demo_coaches_config.php`, fill in the host's database name, user and password.
3. Upload everything (the `.htaccess` file blocks direct downloads of config and SQL files).
4. Log in as `admin` and `clerk` immediately and change both passwords.
5. Delete `db.sql` and `README.md` from the server once the database is imported.

## License
All rights reserved. See `LICENSE`. Viewing for portfolio evaluation only.
