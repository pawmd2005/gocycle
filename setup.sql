CREATE DATABASE IF NOT EXISTS bruteforce_lab CHARACTER
SET
  utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bruteforce_lab;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT UNIQUE,
  id_number VARCHAR(9) NOT NULL PRIMARY KEY,
  first_name VARCHAR(50) NOT NULL,
  middle_name VARCHAR(50) NULL,
  family_name VARCHAR(50) NOT NULL,
  name_extension VARCHAR(20) NULL,
  birthdate DATE NOT NULL,
  age TINYINT UNSIGNED NOT NULL,
  purok VARCHAR(50) NOT NULL,
  barangay VARCHAR(50) NOT NULL,
  city VARCHAR(50) NOT NULL,
  province VARCHAR(50) NOT NULL,
  country VARCHAR(50) NOT NULL,
  zip_code CHAR(4) NOT NULL,
  username VARCHAR(191) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM ('user', 'admin') NOT NULL DEFAULT 'user',
  consent_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL
);

-- Keep the consent timestamp for new registrations without inventing consent for existing users.
SET @gocycle_has_consent_at = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'consent_at'
);
SET @gocycle_consent_migration = IF(
  @gocycle_has_consent_at = 0,
  'ALTER TABLE users ADD COLUMN consent_at DATETIME NULL',
  'SET @gocycle_consent_noop = 1'
);
PREPARE gocycle_consent_stmt FROM @gocycle_consent_migration;
EXECUTE gocycle_consent_stmt;
DEALLOCATE PREPARE gocycle_consent_stmt;

CREATE TABLE IF NOT EXISTS login_attempts (
  username VARCHAR(191) PRIMARY KEY,
  failed_count INT NOT NULL DEFAULT 0,
  locked_until BIGINT NULL,
  last_failed_at DATETIME NULL,
  last_locked_count INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS ip_blocks (
  ip_address VARCHAR(45) PRIMARY KEY,
  failed_count INT NOT NULL DEFAULT 0,
  window_started DATETIME NOT NULL,
  blocked_until DATETIME NULL
);

CREATE TABLE IF NOT EXISTS otp_codes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  purpose ENUM ('register', 'login', 'reset', 'profile') NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, purpose)
);

-- Extend existing OTP tables for authenticated profile password changes.
ALTER TABLE otp_codes
  MODIFY purpose ENUM ('register', 'login', 'reset', 'profile') NOT NULL;

CREATE TABLE IF NOT EXISTS password_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS security_answers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  question_no TINYINT NOT NULL,
  answer_hash CHAR(64) NOT NULL,
  UNIQUE KEY uq_user_question (user_id, question_no)
);

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(40) NOT NULL,
  username_typed VARCHAR(191) NULL,
  user_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(500) NOT NULL,
  details VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (created_at),
  INDEX (event_type)
);

CREATE TABLE IF NOT EXISTS security_flags (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  pattern_name VARCHAR(80) NOT NULL,
  target VARCHAR(191) NOT NULL,
  severity ENUM ('HIGH', 'MED') NOT NULL,
  details VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (pattern_name, target, created_at)
);

CREATE TABLE IF NOT EXISTS ride_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ride_date DATE NOT NULL,
  ride_time TIME NOT NULL,
  status ENUM ('Pending', 'Confirmed', 'Cancelled') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ride_date, ride_time, status),
  INDEX (user_id, ride_date)
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  recipient_role ENUM ('user', 'admin') NOT NULL,
  recipient_user_id INT NULL,
  notification_type VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link_path VARCHAR(255) NOT NULL DEFAULT 'notifications.php',
  is_read TINYINT (1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notification_user (
    recipient_role,
    recipient_user_id,
    is_read,
    created_at
  ),
  INDEX idx_notification_created (created_at)
);
