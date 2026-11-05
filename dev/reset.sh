#!/usr/bin/env bash
# Resets the local dev database and reinstalls (admin: sabrina / Sehr-Sicheres-Dev-Pw1).
cd "$(dirname "$0")"
docker compose exec -T db mariadb -uapp -papp-dev-only talkslot -e \
  "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS mail_outbox, tp_mail_outbox, round_templates, tp_round_templates, audit_log, tp_audit_log, round_translations, tp_round_translations, sent_summaries, bookings, slots, day_breaks, round_days, rounds, pages, rate_limits, settings, users, admin_users, tp_sent_summaries, tp_bookings, tp_slots, tp_day_breaks, tp_round_days, tp_rounds, tp_pages, tp_rate_limits, tp_settings, tp_users, tp_admin_users;"
docker compose exec -T app sh -c 'rm -f /srv/app/app/var/installed.lock; printf "Sehr-Sicheres-Dev-Pw1\nSehr-Sicheres-Dev-Pw1\n" | php /srv/app/bin/install.php sabrina lehrer@example.org >/dev/null && chown -R www-data /srv/app/app/var && echo reset ok'
