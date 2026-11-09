-- Database schema TalkSlot (MariaDB 10.6 or newer, InnoDB, utf8mb4). MySQL is not supported: this file would
-- run there, but the migrations of later updates use MariaDB syntax (the installers refuse other servers).
-- Executed by install.php or bin/install.php.

-- Accounts: role admin = may manage accounts, every person manages only their own rounds.
-- controller_contact: admin only – details for legal notice and privacy policy (placeholder [[VERANTWORTLICHE]]).
-- access_token_hash: SHA-256 of an invitation or password link (single use, with expiry).
-- lang: language of the admin area and of mails to the account (de, en; NULL = admin_language).
-- reply_to: where the replies of parents go (Reply-To of their mails); NULL = email.
-- parent_contact: how parents can reach the account with questions, shown on their pages (ParentContact);
-- NULL = the contact for all (setting parent_contact).
-- summary_names: names in the summaries, if the school allows them (config summary_names); NULL = account from
-- before that setting, which keeps them where allowed until it confirms the notice on the dashboard (Summaries).
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  display_name VARCHAR(200) NOT NULL DEFAULT '',
  email VARCHAR(254) NULL,
  reply_to VARCHAR(254) NULL,
  parent_contact VARCHAR(300) NULL,
  lang VARCHAR(5) NULL,
  controller_contact TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  access_token_hash CHAR(64) NULL,
  access_token_expires_at DATETIME NULL,
  summary_evening TINYINT(1) NOT NULL DEFAULT 1,
  summary_deadline TINYINT(1) NOT NULL DEFAULT 1,
  summary_names TINYINT(1) NULL DEFAULT 0,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_access_token (access_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rounds without bookings, deleted by the teacher: a copy as JSON for RoundTrash::KEEP_DAYS days (see RoundTrash).
CREATE TABLE IF NOT EXISTS round_trash (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  data MEDIUMTEXT NOT NULL,
  deleted_at DATETIME NOT NULL,
  KEY idx_trash_user (user_id),
  KEY idx_trash_deleted (deleted_at),
  CONSTRAINT fk_trash_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- location: where the meetings take place (e.g. a room); a day can override it.
-- was_used: activated at least once or booked by the teacher. Only such rounds expire after their last
-- day (see Cleanup); a draft that was never used, e.g. a copy with the old dates, is kept.
CREATE TABLE IF NOT EXISTS rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  code CHAR(8) NULL,
  code_required TINYINT(1) NOT NULL DEFAULT 0,
  booking_limit VARCHAR(5) NOT NULL DEFAULT 'none',
  remind_parents TINYINT(1) NOT NULL DEFAULT 1,
  description TEXT NULL,
  location VARCHAR(200) NULL,
  source_lang VARCHAR(5) NOT NULL DEFAULT 'de',
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  was_used TINYINT(1) NOT NULL DEFAULT 0,
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
  location VARCHAR(200) NULL,
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
-- reminder_token_hash: a second change token, for the link in the reminder mail (see Reminders).
-- mail_token_hash: a third one, for the link in a confirmation sent later from the outbox (see Outbox).
-- source: who gave the address: the parents when booking, or the teacher (an entry, or a new address for a
-- booking, see BookingService::update()).
-- remind_ok: may get the reminder (parents: as the round said when booking; entries by the teacher: once
-- the parents got a confirmation or change notice). notified_at: last confirmation or change notice sent
-- to the address (no reminder on that day). mail_failed_at: a mail to the address could not be sent.
CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slot_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(254) NULL,
  token_hash CHAR(64) NOT NULL,
  lang VARCHAR(5) NOT NULL DEFAULT 'de',
  source ENUM('parent','admin') NOT NULL DEFAULT 'parent',
  remind_ok TINYINT(1) NOT NULL DEFAULT 0,
  notified_at DATETIME NULL,
  mail_failed_at DATETIME NULL,
  reminded_at DATETIME NULL,
  reminder_token_hash CHAR(64) NULL,
  mail_token_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_bookings_slot (slot_id),
  UNIQUE KEY uq_bookings_token (token_hash),
  UNIQUE KEY uq_bookings_reminder_token (reminder_token_hash),
  UNIQUE KEY uq_bookings_mail_token (mail_token_hash),
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

-- Templates for new rounds (data: times, breaks and settings as JSON; not "settings", that is a table name), maintained by admins, usable by everybody.
CREATE TABLE IF NOT EXISTS round_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  data TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_templates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log of the changes made by admins (who, when, what), without data of parents.
-- Deleted after audit_log_days (default 90). actor: name at the time of the change.
-- action, round_id, params: what the entry is about, structured (App\Audit::PARAM_KEYS, no data of parents).
CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  user_id INT UNSIGNED NULL,
  actor VARCHAR(254) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  action VARCHAR(40) NULL,
  round_id INT UNSIGNED NULL,
  params VARCHAR(500) NULL,
  KEY idx_audit_created (created_at),
  KEY idx_audit_round (round_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
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

-- Mails waiting to be sent (see App\Outbox): references only (booking or account, kind, for summaries the
-- day), no address, text or link – they are created from the booking or account when sending. Deleted once
-- sent, together with the booking or account, and after at most 48 hours if sending keeps failing.
-- payload: only for the cancellation of a booking the teacher deleted (kind 'cancelled'): the booking is gone,
-- so the row keeps what that mail needs (address, language, name, appointment as JSON; no link or token).
CREATE TABLE IF NOT EXISTS mail_outbox (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL,
  booking_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  ref VARCHAR(40) NULL,
  payload TEXT NULL,
  priority TINYINT UNSIGNED NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_outbox_booking (booking_id, kind),
  UNIQUE KEY uq_outbox_account (user_id, kind, ref),
  KEY idx_outbox_due (next_attempt_at),
  CONSTRAINT fk_outbox_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
