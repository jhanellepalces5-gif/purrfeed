CREATE DATABASE IF NOT EXISTS purrfeedn
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE purrfeedn;


-- 1. SCHEDULES
CREATE TABLE IF NOT EXISTS schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    feeding_date DATE NOT NULL,
    feeding_time TIME NOT NULL,
    dispense_seconds DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    status ENUM(
        'Pending',
        'Processing',
        'Completed',
        'Failed',
        'Missed Feeding'
    ) NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_schedules_status_datetime
        (status, feeding_date, feeding_time)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- 2. FEEDER COMMANDS
CREATE TABLE IF NOT EXISTS feeder_commands (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    command_type ENUM('Manual', 'Schedule')
        NOT NULL DEFAULT 'Manual',
    schedule_id INT UNSIGNED NULL DEFAULT NULL,
    dispense_seconds DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    status ENUM(
        'Pending',
        'Processing',
        'Completed',
        'Failed'
    ) NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_commands_status_type_id
        (status, command_type, id),
    INDEX idx_commands_schedule_id (schedule_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- 3. FEEDING HISTORY
CREATE TABLE IF NOT EXISTS feeding_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NULL DEFAULT NULL,
    feeding_type ENUM('Manual', 'Automatic') NOT NULL,
    dispense_seconds DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    status ENUM('Completed', 'Failed') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_history_created_at (created_at),
    INDEX idx_history_feeding_type (feeding_type),
    INDEX idx_history_schedule_id (schedule_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- 4. FEEDER LOCK
CREATE TABLE IF NOT EXISTS feeder_lock (
    id TINYINT UNSIGNED NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    feeding_type VARCHAR(20) NULL DEFAULT NULL,
    feeding_id INT UNSIGNED NULL DEFAULT NULL,
    locked_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- REQUIRED: the backend expects a lock row with id = 1
INSERT IGNORE INTO feeder_lock (id, is_locked)
VALUES (1, 0);


-- VERIFY THE DATABASE TABLES
SHOW TABLES;