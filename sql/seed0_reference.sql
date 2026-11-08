-- DonorNexa reference data (REQUIRED for the app to work)
-- Import after schema.sql. Contains only: cities, settings,
-- the blood compatibility map and one admin account.
-- At the end of the project, import schema.sql and then
-- seed_1, seed_2, seed_3 INSTEAD of this file (they include the same data).
USE donornexa;
SET NAMES utf8mb4;

INSERT INTO cities (id, city_name, province) VALUES
(1, 'Karachi', 'Sindh'),
(2, 'Hyderabad', 'Sindh'),
(3, 'Sukkur', 'Sindh'),
(4, 'Jamshoro', 'Sindh'),
(5, 'Lahore', 'Punjab'),
(6, 'Faisalabad', 'Punjab'),
(7, 'Multan', 'Punjab'),
(8, 'Islamabad', 'Islamabad Capital Territory'),
(9, 'Peshawar', 'Khyber Pakhtunkhwa'),
(10, 'Quetta', 'Balochistan');

INSERT INTO settings (setting_key, setting_value, description) VALUES
('cooldown_days', '90', 'Minimum days between two donations'),
('top_n_notify', '5', 'Donors notified first for a normal request'),
('critical_pool_multiplier', '2', 'Critical requests widen the pool by this factor'),
('max_open_critical', '3', 'Max open critical requests per hospital'),
('no_response_hours', '24', 'Hours before a notification is marked no_response'),
('trust_penalty_cancel', '5', 'Trust points lost per cancelled request');

INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES
(1, 'System Admin', 'admin@donornexa.test', '$2y$10$0xGIE71phB2ZnwC8TMDcUufLlVVuKgrQtB8iyvOeD7Mqin/.YIwo6', 'admin', 'active', NOW() - INTERVAL 235 DAY);

INSERT INTO blood_compatibility (donor_group, recipient_group) VALUES
('O-', 'A+'),
('O-', 'A-'),
('O-', 'B+'),
('O-', 'B-'),
('O-', 'AB+'),
('O-', 'AB-'),
('O-', 'O+'),
('O-', 'O-'),
('O+', 'O+'),
('O+', 'A+'),
('O+', 'B+'),
('O+', 'AB+'),
('A-', 'A-'),
('A-', 'A+'),
('A-', 'AB-'),
('A-', 'AB+'),
('A+', 'A+'),
('A+', 'AB+'),
('B-', 'B-'),
('B-', 'B+'),
('B-', 'AB-'),
('B-', 'AB+'),
('B+', 'B+'),
('B+', 'AB+'),
('AB-', 'AB-'),
('AB-', 'AB+'),
('AB+', 'AB+');