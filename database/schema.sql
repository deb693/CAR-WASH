-- Import into an existing empty database. Compatible with MySQL 8. No CREATE DATABASE privilege needed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS services (
 id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, summary TEXT NOT NULL,
 sort_order INT NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vehicle_classes (
 id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vehicle_makes (
 id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vehicle_models (
 id VARCHAR(64) PRIMARY KEY, make_id VARCHAR(64) NOT NULL, class_id VARCHAR(64) NOT NULL,
 name VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY (make_id) REFERENCES vehicle_makes(id), FOREIGN KEY (class_id) REFERENCES vehicle_classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS packages (
 id VARCHAR(64) PRIMARY KEY, service_id VARCHAR(64) NOT NULL, class_id VARCHAR(64) NOT NULL,
 tier INT NOT NULL, name VARCHAR(100) NOT NULL, summary TEXT NOT NULL,
 price_minor INT NOT NULL, duration_minutes INT NOT NULL, inclusions JSON NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY package_tier (service_id, class_id, tier), CHECK (tier BETWEEN 1 AND 3), CHECK (price_minor >= 0),
 FOREIGN KEY (service_id) REFERENCES services(id), FOREIGN KEY (class_id) REFERENCES vehicle_classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS package_price_overrides (
 package_id VARCHAR(64) NOT NULL, model_id VARCHAR(64) NOT NULL, price_minor INT NOT NULL,
 PRIMARY KEY (package_id, model_id), FOREIGN KEY (package_id) REFERENCES packages(id), FOREIGN KEY (model_id) REFERENCES vehicle_models(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS add_ons (
 id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, price_minor INT NOT NULL,
 duration_minutes INT NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS package_add_ons (
 package_id VARCHAR(64) NOT NULL, add_on_id VARCHAR(64) NOT NULL,
 PRIMARY KEY (package_id, add_on_id), FOREIGN KEY (package_id) REFERENCES packages(id), FOREIGN KEY (add_on_id) REFERENCES add_ons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS service_areas (
 id INT AUTO_INCREMENT PRIMARY KEY, postcode VARCHAR(20) UNIQUE NOT NULL,
 name VARCHAR(100) NOT NULL, surcharge_minor INT NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS blocked_dates (day DATE PRIMARY KEY, reason VARCHAR(200) NOT NULL)
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scheduling_locks (day DATE PRIMARY KEY)
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quotes (
 id CHAR(48) PRIMARY KEY, selection_json JSON NOT NULL, snapshot_json JSON NOT NULL,
 fingerprint CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bookings (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(32) UNIQUE NOT NULL,
 status VARCHAR(40) NOT NULL, intent VARCHAR(40) NOT NULL, customer_json JSON NOT NULL,
 address VARCHAR(500) NOT NULL, postcode VARCHAR(20) NOT NULL, selection_json JSON NOT NULL, snapshot_json JSON NOT NULL,
 starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, timezone VARCHAR(100) NOT NULL,
 message TEXT NOT NULL, consent_version VARCHAR(64) NOT NULL, consent_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX allocation (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS enquiries (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(32) UNIQUE NOT NULL, status VARCHAR(40) NOT NULL,
 customer_json JSON NOT NULL, context_json JSON NOT NULL, message TEXT NOT NULL,
 consent_version VARCHAR(64) NOT NULL, consent_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS idempotency (
 operation VARCHAR(40) NOT NULL, request_key VARCHAR(100) NOT NULL, payload_hash CHAR(64) NOT NULL,
 response_json JSON NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (operation, request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT NOT NULL DEFAULT 1, expires_at DATETIME NOT NULL, INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

