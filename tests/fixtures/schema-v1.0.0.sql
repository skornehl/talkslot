-- Datenbankschema TalkSlot (MariaDB/MySQL, InnoDB, utf8mb4)
-- Wird von install.php bzw. bin/install.php ausgeführt.

-- Konten: role admin = darf Konten verwalten, jede Person verwaltet nur eigene Runden.
-- controller_contact: nur beim Admin – Angaben für Impressum und Datenschutz (Platzhalter [[VERANTWORTLICHE]]).
-- access_token_hash: SHA-256 eines Einladungs- bzw. Passwort-Links (einmalig, mit Ablaufzeit).
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

-- is_conflict: gebuchter Slot, der nach einer Änderung von Zeiten/Pausen
-- nicht mehr zum Zeitplan passt. Wird nie automatisch gelöscht.
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

-- UNIQUE(slot_id) verhindert Doppelbuchungen auf Datenbankebene.
-- token_hash: SHA-256 des Änderungstokens, der Klartext wird nicht gespeichert.
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

-- key_hash: HMAC der gekürzten IP-Adresse, Einträge max. 24 h
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

-- Versandprotokoll der Zusammenfassungs-Mails (verhindert Doppelversand), ohne Inhalte
CREATE TABLE IF NOT EXISTS sent_summaries (
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  ref VARCHAR(40) NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind, ref),
  CONSTRAINT fk_summaries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Übersetzungen von Titel/Beschreibung einer Runde (im Browser der Lehrkraft oder von Hand).
-- source_hash: Hash des Originals zum Zeitpunkt der Übersetzung (veraltet, wenn abweichend).
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
