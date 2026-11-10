#!/usr/bin/env bash
# Resets the local dev database and reinstalls (admin: sabrina / Sehr-Sicheres-Dev-Pw1, operated by the school).
# Also drops tables that an interrupted tests/integration.php left behind (prefix it<hex>_).
cd "$(dirname "$0")"
docker compose exec -T db mariadb -uapp -papp-dev-only talkslot -e \
  "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS security_events, tp_security_events, round_trash, tp_round_trash, mail_outbox, tp_mail_outbox, round_templates, tp_round_templates, audit_log, tp_audit_log, round_translations, tp_round_translations, sent_summaries, bookings, slots, day_breaks, round_days, rounds, pages, rate_limits, settings, users, admin_users, tp_sent_summaries, tp_bookings, tp_slots, tp_day_breaks, tp_round_days, tp_rounds, tp_pages, tp_rate_limits, tp_settings, tp_users, tp_admin_users;
   SET @t = (SELECT GROUP_CONCAT('\`', table_name, '\`') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name REGEXP '^it[0-9a-f]{8}_');
   SET @s = IF(@t IS NULL, 'DO 0', CONCAT('DROP TABLE ', @t)); PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;"
# The error log exists before chown, so that Apache (www-data) can write to it even if a command line call as
# root logs first; otherwise PHP would log web requests to Apache's stderr, where tests/php-errors.sh misses them
docker compose exec -T app sh -c 'rm -f /srv/app/app/var/installed.lock; printf "Sehr-Sicheres-Dev-Pw1\nSehr-Sicheres-Dev-Pw1\nschool\n" | php /srv/app/bin/install.php sabrina lehrer@example.org >/dev/null && touch /srv/app/app/var/php-error.log && chown -R www-data /srv/app/app/var && echo reset ok'
