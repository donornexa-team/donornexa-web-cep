-- schema.sql

-- Target: MariaDB 10.4+ / MySQL 5.7+ (XAMPP).  Engine InnoDB, utf8mb4.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS donornexa;
CREATE DATABASE donornexa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE donornexa;

-- -------------------------
-- ACCOUNTS & ACCESS
-- ----------------------

-- Lookup table of cities; managed by the admin.
CREATE TABLE cities (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    city_name VARCHAR(80)  NOT NULL,
    province  ENUM('Sindh','Punjab','Khyber Pakhtunkhwa','Balochistan',
                   'Islamabad Capital Territory','Gilgit-Baltistan',
                   'Azad Jammu & Kashmir') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cities_name (city_name)
) ENGINE=InnoDB;

-- System settings edited by the admin (e.g. cool-down days).
-- Key/value keeps the schema stable when a new setting is added.
CREATE TABLE settings (
    setting_key   VARCHAR(50)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    description   VARCHAR(255) NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

-- One row per login account, whatever the role.
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,           -- password_hash(),
    role          ENUM('admin','hospital','donor') NOT NULL,
    status        ENUM('active','blocked')         NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    -- INDEX JUSTIFICATION: admin screens filter/sort users by role+status.
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB;

-- Donor profile (1:1 with users where role = 'donor').
CREATE TABLE donors (
    user_id             INT UNSIGNED NOT NULL,
    blood_group         ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    dob                 DATE         NOT NULL,
    weight_kg           DECIMAL(5,2) NOT NULL,
    cnic                VARCHAR(15)  NULL,         -- format #####-#######-#
    city_id             INT UNSIGNED NOT NULL,
    phone               VARCHAR(12)  NOT NULL,     -- format 03XX-XXXXXXX
    last_donation_date  DATE         NULL,
    is_available        TINYINT(1)   NOT NULL DEFAULT 1,
    photo               VARCHAR(255) NULL,         -- renamed file name only
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_donors_cnic (cnic),
    -- INDEX JUSTIFICATION: the matching query filters by group + city +
    -- availability on every request, so this composite index is on the
    -- hottest path of the system.
    KEY idx_donors_match (blood_group, city_id, is_available),
    CONSTRAINT chk_donors_weight CHECK (weight_kg BETWEEN 30 AND 250),
    CONSTRAINT fk_donors_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_donors_city FOREIGN KEY (city_id)
        REFERENCES cities (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Hospital profile (1:1 with users where role = 'hospital').
CREATE TABLE hospitals (
    user_id             INT UNSIGNED NOT NULL,
    hospital_name       VARCHAR(150) NOT NULL,
    licence_no          VARCHAR(50)  NOT NULL,
    licence_file        VARCHAR(255) NOT NULL,     -- renamed PDF file name only
    city_id             INT UNSIGNED NOT NULL,
    address             VARCHAR(255) NULL,
    phone               VARCHAR(12)  NOT NULL,
    verification_status ENUM('pending','verified','rejected')
                                     NOT NULL DEFAULT 'pending',
    trust_score         TINYINT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_hospitals_licence (licence_no),
    -- INDEX JUSTIFICATION: admin verification queue filters on status.
    KEY idx_hospitals_status (verification_status),
    CONSTRAINT chk_hospitals_trust CHECK (trust_score <= 100),
    CONSTRAINT fk_hospitals_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_hospitals_city FOREIGN KEY (city_id)
        REFERENCES cities (id) ON DELETE RESTRICT
) ENGINE=InnoDB;



-- -----------------------------------------
-- P2: REQUESTS & MATCHING
-- ---------------------------------------------

-- Data-driven ABO/Rh compatibility map (NOT if/else chains).
-- A row means: donor_group can donate to recipient_group.
CREATE TABLE blood_compatibility (
    donor_group     ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    recipient_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    PRIMARY KEY (donor_group, recipient_group),
    -- INDEX JUSTIFICATION: "who can donate to X" searches by recipient first.
    KEY idx_compat_recipient (recipient_group)
) ENGINE=InnoDB;

CREATE TABLE blood_requests (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hospital_id      INT UNSIGNED NOT NULL,        -- = hospitals.user_id
    blood_group      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed     TINYINT UNSIGNED NOT NULL,
    units_fulfilled  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    urgency          ENUM('critical','urgent','normal') NOT NULL DEFAULT 'normal',
    required_by      DATETIME     NOT NULL,
    ward_notes       VARCHAR(255) NULL,            -- never patient names
    status           ENUM('open','fulfilled','expired','cancelled','closed')
                                  NOT NULL DEFAULT 'open',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Second safety net behind the FOR UPDATE transaction:
    -- the database itself refuses over-allocation.
    CONSTRAINT chk_req_units CHECK (units_needed BETWEEN 1 AND 20),
    CONSTRAINT chk_req_fulfilled CHECK (units_fulfilled <= units_needed),
    -- INDEX JUSTIFICATION: public board + auto-expiry scan open requests
    -- ordered by deadline.
    KEY idx_req_status_deadline (status, required_by),
    -- INDEX JUSTIFICATION: hospital dashboard lists its own requests;
    -- the anti-misuse rule counts its open critical ones.
    KEY idx_req_hospital (hospital_id, status, urgency),
    CONSTRAINT fk_req_hospital FOREIGN KEY (hospital_id)
        REFERENCES hospitals (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE request_matches (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id    INT UNSIGNED NOT NULL,
    donor_id      INT UNSIGNED NOT NULL,           -- = donors.user_id
    score         DECIMAL(6,2) NOT NULL DEFAULT 0,
    status        ENUM('notified','accepted','declined','no_response','donated')
                               NOT NULL DEFAULT 'notified',
    decline_reason VARCHAR(255) NULL,
    notified_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at  DATETIME     NULL,
    PRIMARY KEY (id),
    -- A donor can be matched to a given request only once.
    UNIQUE KEY uq_match_request_donor (request_id, donor_id),
    -- INDEX JUSTIFICATION: donor inbox and response-rate calculation
    -- filter by donor + status.
    KEY idx_match_donor_status (donor_id, status),
    CONSTRAINT fk_match_request FOREIGN KEY (request_id)
        REFERENCES blood_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_donor FOREIGN KEY (donor_id)
        REFERENCES donors (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE donations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id    INT UNSIGNED NOT NULL,
    units       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    donated_on  DATE         NOT NULL,
    verified_by INT UNSIGNED NULL,                 -- hospital/admin user
    PRIMARY KEY (id),
    -- One donation record per accepted match.
    UNIQUE KEY uq_donation_match (match_id),
    -- INDEX JUSTIFICATION: donation history & badge counts by date.
    KEY idx_donation_date (donated_on),
    CONSTRAINT chk_donation_units CHECK (units BETWEEN 1 AND 2),
    CONSTRAINT fk_donation_match FOREIGN KEY (match_id)
        REFERENCES request_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_donation_verifier FOREIGN KEY (verified_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(255) NOT NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- INDEX JUSTIFICATION: unread badge counts per user.
    KEY idx_notif_user_read (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every status change is recorded here. user_id is kept NULL-able with
-- SET NULL so the history survives if an account is deleted.
CREATE TABLE audit_logs (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(50)  NOT NULL,              -- e.g. request.status_change
    entity     VARCHAR(50)  NOT NULL,              -- e.g. blood_requests
    entity_id  INT UNSIGNED NULL,
    old_value  VARCHAR(255) NULL,
    new_value  VARCHAR(255) NULL,
    ip_address VARCHAR(45)  NOT NULL,              -- fits IPv6
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- INDEX JUSTIFICATION: admin audit view filters by entity and date.
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- VIEW: donors who pass every eligibility rule right now.
-- Age 18-60, weight >= 50 kg, available, account active, and last
-- donation at least `cooldown_days` ago (admin-configurable setting).
CREATE VIEW v_eligible_donors AS
SELECT d.user_id,
       u.name,
       d.blood_group,
       d.city_id,
       d.phone,
       d.last_donation_date,
       TIMESTAMPDIFF(YEAR, d.dob, CURDATE()) AS age
FROM donors d
JOIN users u ON u.id = d.user_id
WHERE u.status = 'active'
  AND d.is_available = 1
  AND d.weight_kg >= 50
  AND TIMESTAMPDIFF(YEAR, d.dob, CURDATE()) BETWEEN 18 AND 60
  AND (d.last_donation_date IS NULL
       OR DATEDIFF(CURDATE(), d.last_donation_date) >=
          (SELECT CAST(setting_value AS UNSIGNED)
             FROM settings WHERE setting_key = 'cooldown_days'));

-- TRIGGER: after a donation is recorded, move the donor's
-- last_donation_date forward (never backwards) and mark the match.
-- Written as a single statement so no DELIMITER is needed on import.
CREATE TRIGGER trg_donation_after_insert
AFTER INSERT ON donations
FOR EACH ROW
UPDATE donors d
JOIN request_matches m ON m.donor_id = d.user_id
SET d.last_donation_date = GREATEST(IFNULL(d.last_donation_date, NEW.donated_on),
                                    NEW.donated_on)
WHERE m.id = NEW.match_id;
-- -----------------------------
-- PUBLIC, INVENTORY & INSIGHTS 
-- -----------------------------
-- Units of each group currently held by each hospital.
CREATE TABLE blood_inventory (
    hospital_id     INT UNSIGNED NOT NULL,
    blood_group     ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_available INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hospital_id, blood_group),
    CONSTRAINT fk_inventory_hospital FOREIGN KEY (hospital_id)
        REFERENCES hospitals (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- VIEW: demand (units still needed on open requests) vs supply
-- (eligible donors) per blood group, for the admin chart.
CREATE VIEW v_demand_vs_supply AS
SELECT g.blood_group,
       COALESCE(dem.units_needed, 0) AS units_demanded,
       COALESCE(sup.donors, 0)       AS eligible_donors
FROM (SELECT 'A+' AS blood_group UNION ALL SELECT 'A-' UNION ALL SELECT 'B+'
      UNION ALL SELECT 'B-' UNION ALL SELECT 'AB+' UNION ALL SELECT 'AB-'
      UNION ALL SELECT 'O+' UNION ALL SELECT 'O-') g
LEFT JOIN (SELECT blood_group, SUM(units_needed - units_fulfilled) AS units_needed
             FROM blood_requests WHERE status = 'open'
             GROUP BY blood_group) dem ON dem.blood_group = g.blood_group
LEFT JOIN (SELECT blood_group, COUNT(*) AS donors
             FROM v_eligible_donors GROUP BY blood_group) sup
       ON sup.blood_group = g.blood_group;

SET FOREIGN_KEY_CHECKS = 1;