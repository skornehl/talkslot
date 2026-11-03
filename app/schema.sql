-- Database schema TalkSlot (MariaDB/MySQL, InnoDB, utf8mb4)
-- Executed by install.php or bin/install.php.

-- Accounts: role admin = may manage accounts, every person manages only their own rounds.
-- controller_contact: admin only – details for legal notice and privacy policy (placeholder [[VERANTWORTLICHE]]).
-- access_token_hash: SHA-256 of an invitation or password link (single use, with expiry).
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  display_name VARCHAR(200) NOT NULL DEFAULT '',
  email VARCHAR(254) NULL,
  controller_contact TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  access_token_hash CHAR(64) NULL,
  access_token_expires_at DATETIME NULL,
  summary_evening TINYINT(1) NOT NULL DEFAULT 1,
  summary_deadline TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_access_token (access_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  code CHAR(8) NULL,
  code_required TINYINT(1) NOT NULL DEFAULT 0,
  booking_limit VARCHAR(5) NOT NULL DEFAULT 'none',
  description TEXT NULL,
  source_lang VARCHAR(5) NOT NULL DEFAULT 'de',
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  booking_deadline_hours INT UNSIGNED NOT NULL DEFAULT 24,
  cancel_deadline_hours INT UNSIGNED NOT NULL DEFAULT 12,
  delete_after_hours INT UNSIGNED NULL DEFAULT 24,
  expired_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_rounds_slug (slug),
  UNIQUE KEY uq_rounds_code (code),
  KEY idx_rounds_user (user_id),
  CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS round_days (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  round_id INT UNSIGNED NOT NULL,
  day_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  slot_minutes SMALLINT UNSIGNED NOT NULL,
  KEY idx_days_round (round_id, day_date),
  CONSTRAINT fk_days_round FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS day_breaks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day_id INT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  label VARCHAR(100) NULL,
  KEY idx_breaks_day (day_id),
  CONSTRAINT fk_breaks_day FOREIGN KEY (day_id) REFERENCES round_days (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_conflict: booked slot that no longer fits the schedule after a change to times/breaks.
-- Never deleted automatically.
CREATE TABLE IF NOT EXISTS slots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day_id INT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  is_conflict TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slots_day_start (day_id, start_time),
  CONSTRAINT fk_slots_day FOREIGN KEY (day_id) REFERENCES round_days (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UNIQUE(slot_id) prevents double bookings at database level.
-- token_hash: SHA-256 of the change token; the plain text is not stored.
CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slot_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(254) NULL,
  token_hash CHAR(64) NOT NULL,
  lang VARCHAR(5) NOT NULL DEFAULT 'de',
  source ENUM('parent','admin') NOT NULL DEFAULT 'parent',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_bookings_slot (slot_id),
  UNIQUE KEY uq_bookings_token (token_hash),
  CONSTRAINT fk_bookings_slot FOREIGN KEY (slot_id) REFERENCES slots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  page_key VARCHAR(20) NOT NULL,
  lang VARCHAR(5) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (page_key, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- key_hash: HMAC of the truncated IP address, entries kept for at most 24 h
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(20) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, key_hash),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery log of the summary emails (prevents duplicate sends), without content
CREATE TABLE IF NOT EXISTS sent_summaries (
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  ref VARCHAR(40) NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind, ref),
  CONSTRAINT fk_summaries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Translations of a round's title/description (in the teacher's browser or by hand).
-- source_hash: hash of the original at translation time (stale if it differs).
CREATE TABLE IF NOT EXISTS round_translations (
  round_id INT UNSIGNED NOT NULL,
  lang VARCHAR(5) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  source_hash CHAR(64) NOT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (round_id, lang),
  CONSTRAINT fk_translations_round FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
