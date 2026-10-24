-- =============================================
-- DEMO COACHES BUS RESERVATION SYSTEM
-- DATABASE SETUP SCRIPT (Updated with Clerk Role)
-- Run this in phpMyAdmin > demo_coaches > SQL
-- =============================================

-- On shared hosting the database already exists: delete the next two lines and import into it.
CREATE DATABASE IF NOT EXISTS demo_coaches;
USE demo_coaches;

-- -------------------------- 
-- TABLE 1: BUSES
-- --------------------------
CREATE TABLE buses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plate_number VARCHAR(20) NOT NULL UNIQUE,   -- booking_ref is built from the plate, so it must be unique
    bus_type    VARCHAR(50) DEFAULT 'Scania Master',
    total_seats INT         DEFAULT 45
);

INSERT INTO buses (plate_number, bus_type) VALUES
('DEMO 001', 'Scania Master'),
('DEMO 002', 'Scania Master');

-- -------------------------- 
-- TABLE 2: TRIPS
-- --------------------------
CREATE TABLE trips (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    bus_id         INT,
    route          VARCHAR(100) NOT NULL,
    origin         VARCHAR(50)  NOT NULL,
    destination    VARCHAR(50)  NOT NULL,
    via            VARCHAR(50)  DEFAULT NULL,
    departure_time TIME         NOT NULL,
    fare           DECIMAL(10,2) CHECK (fare BETWEEN 100 AND 2500),
    trip_date      DATE,
    FOREIGN KEY (bus_id) REFERENCES buses(id),
    INDEX idx_trips_bus_date (bus_id, trip_date)
);

-- Demo trips for the next 28 days (the booking page only shows trips within 28 days of today).
-- Re-run this block (or add trips in the admin panel) every few weeks to keep the demo populated.
INSERT INTO trips (bus_id, route, origin, destination, via, departure_time, fare, trip_date)
SELECT s.bus_id, s.route, s.origin, s.destination, s.via, s.departure_time, s.fare,
       CURDATE() + INTERVAL (a.d * 10 + b.d) DAY
FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2) a
CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
CROSS JOIN (
    SELECT 1 AS bus_id, 'NAIROBI-UKUNDA'  AS route, 'NAIROBI' AS origin, 'UKUNDA'  AS destination, 'MOMBASA' AS via, '20:00' AS departure_time, 1700.00 AS fare
    UNION ALL SELECT 1, 'NAIROBI-MOMBASA', 'NAIROBI', 'MOMBASA', NULL,      '20:00', 1500.00
    UNION ALL SELECT 2, 'UKUNDA-NAIROBI',  'UKUNDA',  'NAIROBI', 'MOMBASA', '20:00', 1700.00
    UNION ALL SELECT 2, 'MOMBASA-NAIROBI', 'MOMBASA', 'NAIROBI', NULL,      '21:30', 1500.00
) s
WHERE (a.d * 10 + b.d) <= 27;

-- -------------------------- 
-- TABLE 3: BOOKINGS
-- --------------------------
CREATE TABLE bookings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    booking_ref    VARCHAR(30)  UNIQUE,
    trip_id        INT,
    seat_number    INT          NOT NULL,
    full_name      VARCHAR(100) NOT NULL,
    id_number      VARCHAR(20)  NOT NULL,
    phone_number   VARCHAR(10)  NOT NULL,
    payment_method ENUM('cash_at_terminal', 'call_office') NOT NULL,
    status         ENUM('pending', 'confirmed') DEFAULT 'pending',
    booked_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id),
    UNIQUE KEY uq_trip_seat (trip_id, seat_number),   -- a seat can never be sold twice on one trip
    INDEX idx_pending (status, booked_at)
);

-- -------------------------- 
-- TABLE 4: CUSTOMER FEEDBACK
-- --------------------------
CREATE TABLE feedback (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    first_name   VARCHAR(60)  NOT NULL,
    last_name    VARCHAR(60)  NOT NULL,
    email        VARCHAR(120) NOT NULL,
    phone        VARCHAR(15)  NOT NULL,
    subject      VARCHAR(200) NOT NULL,
    message      TEXT         NOT NULL,
    is_read      TINYINT(1)   DEFAULT 0,
    admin_reply  TEXT         DEFAULT NULL,
    replied_at   DATETIME     DEFAULT NULL,
    submitted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------- 
-- TABLE 5: ADMIN CREDENTIALS (Updated with role)
-- --------------------------
CREATE TABLE IF NOT EXISTS admin_credentials (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100) NOT NULL,
    role          ENUM('admin', 'clerk') DEFAULT 'admin',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------
-- TABLE 6: LOGIN ATTEMPTS (brute-force protection, tracked by IP and username)
-- --------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(60) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_ip   (ip_address, attempted_at),
    INDEX idx_la_user (username, attempted_at)
);

-- --------------------------
-- TABLE 7: RATE LIMITS (public booking + contact form abuse protection)
-- --------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    action     VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl (action, ip_address, created_at)
);

-- Default accounts share one starter password hash. must_change_password = 1 forces
-- each person to choose their own password at first login.
-- Default Admin Account
INSERT INTO admin_credentials (username, password_hash, display_name, role, must_change_password)
VALUES (
    'admin',
    '$2y$12$S0YetpamxiTM1HhOIbZkieSzphIr/ek.JkHgBoeXhC8bWzTvqzrTS',
    'Administrator',
    'admin',
    1
) ON DUPLICATE KEY UPDATE username = username;

-- Clerk Account
INSERT INTO admin_credentials (username, password_hash, display_name, role, must_change_password)
VALUES (
    'clerk',
    '$2y$12$S0YetpamxiTM1HhOIbZkieSzphIr/ek.JkHgBoeXhC8bWzTvqzrTS',
    'Booking Clerk',
    'clerk',
    1
) ON DUPLICATE KEY UPDATE username = username;

-- =============================================
-- DONE! Your database is ready.
-- =============================================