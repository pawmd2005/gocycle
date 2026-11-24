-- GoCycle brute-force lab: run once in phpMyAdmin (SQL tab) or:
--   mysql -u root < setup.sql
-- Safe to re-run: it only creates / adds what is missing (XAMPP/MariaDB).

CREATE DATABASE IF NOT EXISTS bruteforce_lab
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bruteforce_lab;

CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(191) NOT NULL,
  email      VARCHAR(254) NULL,
  password   VARCHAR(255) NOT NULL,
  role       ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- If your users table already existed, add the new columns:
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(254) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user','admin') NOT NULL DEFAULT 'user';
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
-- Enforce one account per username / email (fails only if duplicates already exist):
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_username (username);
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_email (email);
ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;   -- room for password_hash()

-- Failed-attempt counter + lock time (unix timestamp). One row per username.
CREATE TABLE IF NOT EXISTS login_attempts (
  username     VARCHAR(191) PRIMARY KEY,
  failed_count INT    NOT NULL DEFAULT 0,
  locked_until BIGINT NULL
);
ALTER TABLE login_attempts MODIFY locked_until BIGINT NULL;

-- Make yourself admin (change the username to an account you registered):
-- UPDATE users SET role = 'admin' WHERE username = 'pau';


-- GoCycle ride reservations (5 seats per time slot are enforced in dashboard.php).
CREATE TABLE IF NOT EXISTS ride_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ride_date DATE NOT NULL,
  ride_time TIME NOT NULL,
  status ENUM('Booked','Cancelled') NOT NULL DEFAULT 'Booked',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active_key VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status='Booked' THEN CONCAT(user_id, '-', ride_date, '-', ride_time) ELSE NULL END) STORED,
  UNIQUE KEY uq_active_booking (active_key),
  INDEX idx_ride_slot (ride_date, ride_time, status),
  INDEX idx_user_bookings (user_id, ride_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
