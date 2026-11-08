<?php
declare(strict_types=1);

namespace App;

/**
 * Protection of the accounts against a taken-over or misused admin session (expert review P1-5).
 *
 * Changes that could take over or lock out an account ask for the password of the acting admin again
 * (needsPassword()), at least one active admin always remains, also when two admins act at the same
 * moment (keepingAnAdmin()), and security notices go out by e-mail, so that such a change does not go
 * unnoticed (and a data breach can be detected at all):
 * - to the account holder when its password was changed or set,
 * - to the previous address when the e-mail address or the username of an account changed,
 * - to all active admins and the account concerned when admin rights change (granted, invited as admin,
 *   taken away, admin account deactivated, reactivated or deleted) or all sessions were ended.
 * Every mail is written in the language of its recipient. Sending is best effort: a failure is logged
 * and reported to the operator by Mailer, the change itself stays.
 */
final class AccountSecurity
{
    public const INVITED = 'invited';
    public const GRANTED = 'granted';
    public const REVOKED = 'revoked';
    public const DEACTIVATED = 'deactivated';
    public const REACTIVATED = 'reactivated';
    public const DELETED = 'deleted';
    public const SESSIONS_ENDED = 'sessions';

    /**
     * Does saving the account form need the password of the acting admin? For admin rights (a new admin,
     * the role changes, an admin account is deactivated or reactivated) and for e-mail addresses
     * (password links go there). $before is null for a new account.
     */
    public static function needsPassword(?array $before, array $after): bool
    {
        if ($before === null) {
            return $after['role'] === 'admin';
        }
        return $after['role'] !== $before['role']
            || ($before['role'] === 'admin' && (int) $after['is_active'] !== (int) $before['is_active'])
            || mb_strtolower((string) $after['email']) !== mb_strtolower((string) $before['email']);
    }

    /** Which change of admin rights saving the account form makes (constant), or null. */
    public static function adminEvent(array $before, array $after): ?string
    {
        if ($after['role'] !== $before['role']) {
            return $after['role'] === 'admin' ? self::GRANTED : self::REVOKED;
        }
        if ($before['role'] === 'admin' && (int) $after['is_active'] !== (int) $before['is_active']) {
            return $after['is_active'] ? self::REACTIVATED : self::DEACTIVATED;
        }
        return null;
    }

    /**
     * Runs $change (an UPDATE or DELETE of account $userId) in a transaction that keeps at least one
     * active admin. The rows of the active admins are locked first (SELECT … FOR UPDATE): when two admins
     * demote, deactivate or delete each other at the same moment, the second one waits for the first and
     * then sees its change, instead of both succeeding with a count read before.
     * @param bool $remainsAdmin whether the account is still an active admin after the change
     * @return bool false (nothing changed) if no active admin would remain
     */
    public static function keepingAnAdmin(int $userId, bool $remainsAdmin, \Closure $change): bool
    {
        return (bool) Db::transaction(static function () use ($userId, $remainsAdmin, $change): bool {
            $admins = array_map('intval', array_column(Db::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"), 'id'));
            if (!$remainsAdmin && array_diff($admins, [$userId]) === []) {
                return false;
            }
            $change();
            return true;
        });
    }

    /** Password changed (profile) or set (invitation/password link): to the account holder. */
    public static function passwordChanged(array $user): void
    {
        self::send($user, static fn (): array => self::passwordChangedText($user));
    }

    /**
     * E-mail address or username changed, by the account holder ($actor is the account) or by an admin:
     * to the previous address, which would otherwise not learn that reset links now go elsewhere.
     */
    public static function contactChanged(array $before, array $after, array $actor): void
    {
        if (mb_strtolower((string) $after['email']) !== mb_strtolower((string) $before['email']) || $after['username'] !== $before['username']) {
            self::send($before, static fn (): array => self::contactChangedText($before, $after, $actor));
        }
    }

    /**
     * Admin rights changed ($event, see constants) or all sessions ended: to all active admins (the
     * acting one too – its session may be the one taken over) and to the account concerned, unless it
     * is $account of an invitation (it gets the invitation instead). One mail per address.
     */
    public static function adminChange(string $event, ?array $account, array $actor): void
    {
        $recipients = Db::all("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id");
        if ($event === self::INVITED) {
            $recipients = array_filter($recipients, static fn (array $r): bool => (int) $r['id'] !== (int) $account['id']);
        } elseif ($account !== null) {
            $recipients[] = $account;
        }
        $byAddress = [];
        foreach ($recipients as $recipient) {
            $byAddress[mb_strtolower((string) $recipient['email'])] ??= $recipient;
        }
        foreach ($byAddress as $recipient) {
            self::send($recipient, static fn (): array => self::adminChangeText($event, $account, $actor));
        }
    }

    /** An admin took over a round to see the parents' data (Substitution): to the teacher of the round. */
    public static function substitution(array $round, array $owner, array $actor): void
    {
        self::send($owner, static function () use ($round, $actor): array {
            $vars = self::vars(['title' => (string) $round['title'], 'actor' => self::name($actor)]);
            return [__('Vertretung für Ihre Runde „{title}“', $vars), [
                __('{actor} (Admin) hat bei {app} ({url}) die Vertretung für Ihre Runde „{title}“ übernommen und kann die Namen und E-Mail-Adressen der Eltern sehen und Buchungen bearbeiten, bis diese Anmeldung endet. Der Vorgang steht im Protokoll.', $vars),
                '',
                __('War das nicht abgesprochen, wenden Sie sich bitte umgehend an einen anderen Admin.'),
            ]];
        });
    }

    /** @return array{0:string,1:list<string>} [subject, lines] */
    private static function passwordChangedText(array $user): array
    {
        $vars = self::vars(['username' => $user['username']]);
        // The first password (invitation, never signed in yet): nothing was changed, no session ended
        $first = ($user['last_login_at'] ?? null) === null;
        return [$first ? __('Ihr Passwort wurde festgelegt') : __('Ihr Passwort wurde geändert'), [
            $first
                ? __('für Ihr Konto „{username}“ bei {app} ({url}) wurde soeben das Passwort festgelegt.', $vars)
                : __('für Ihr Konto „{username}“ bei {app} ({url}) wurde soeben ein neues Passwort festgelegt. Andere Anmeldungen des Kontos wurden dabei beendet.', $vars),
            '',
            __('Waren Sie das nicht, legen Sie bitte über „Passwort vergessen?“ auf der Anmeldeseite ein neues Passwort fest und wenden Sie sich umgehend an einen Admin.'),
        ]];
    }

    /** @return array{0:string,1:list<string>} [subject, lines] */
    private static function contactChangedText(array $before, array $after, array $actor): array
    {
        $emailChanged = mb_strtolower((string) $after['email']) !== mb_strtolower((string) $before['email']);
        $usernameChanged = $after['username'] !== $before['username'];
        $self = (int) $actor['id'] === (int) $before['id'];
        $vars = self::vars(['email' => (string) $after['email'], 'username' => $after['username'], 'actor' => self::name($actor)]);
        if ($self && !$usernameChanged) {
            // Own e-mail address (profile)
            $lines = [(string) $after['email'] !== ''
                ? __('für Ihr Konto bei {app} ({url}) wurde die E-Mail-Adresse geändert auf {email}.', $vars)
                : __('für Ihr Konto bei {app} ({url}) wurde die E-Mail-Adresse geändert (keine Adresse mehr hinterlegt).', $vars)];
        } else {
            $lines = array_values(array_filter([
                $self
                    ? __('für Ihr Konto bei {app} ({url}) wurde Folgendes geändert:', $vars)
                    : __('für Ihr Konto bei {app} ({url}) hat {actor} (Admin) Folgendes geändert:', $vars),
                $emailChanged ? __('– E-Mail-Adresse jetzt: {email}', $vars) : '',
                $usernameChanged ? __('– Benutzername jetzt: {username}', $vars) : '',
            ]));
        }
        if ($emailChanged && (string) $after['email'] !== '') {
            $lines[] = __('Links zum Festlegen eines neuen Passworts gehen ab jetzt an die neue Adresse.');
        }
        $lines[] = '';
        $lines[] = $self
            ? __('Waren Sie das nicht, wenden Sie sich bitte umgehend an einen Admin.')
            : __('War das nicht abgesprochen, wenden Sie sich bitte umgehend an einen anderen Admin.');
        return [$emailChanged ? __('Ihre E-Mail-Adresse wurde geändert') : __('Ihr Benutzername wurde geändert'), $lines];
    }

    /** @return array{0:string,1:list<string>} [subject, lines] */
    private static function adminChangeText(string $event, ?array $account, array $actor): array
    {
        $vars = self::vars($account ? ['account' => self::name($account), 'username' => $account['username']] : []);
        $what = match ($event) {
            self::INVITED => __('„{account}“ ({username}) wurde als Admin eingeladen.', $vars),
            self::GRANTED => __('„{account}“ ({username}) hat Admin-Rechte erhalten.', $vars),
            self::REVOKED => __('„{account}“ ({username}) hat keine Admin-Rechte mehr.', $vars),
            self::DEACTIVATED => __('Das Admin-Konto „{account}“ ({username}) wurde deaktiviert.', $vars),
            self::REACTIVATED => __('Das Admin-Konto „{account}“ ({username}) wurde wieder aktiviert.', $vars),
            self::DELETED => __('Das Admin-Konto „{account}“ ({username}) wurde gelöscht.', $vars),
            default => __('Alle Sitzungen wurden beendet: Alle Konten müssen sich neu anmelden.'),
        };
        return [__('Sicherheitshinweis: Änderung im Verwaltungsbereich von {app}', $vars), [
            __('im Verwaltungsbereich von {app} ({url}) wurde gerade Folgendes geändert:', $vars),
            '',
            $what,
            __('Durch: {actor}', ['actor' => self::name($actor)]),
            '',
            $account
                ? __('Diese Nachricht geht an alle Admins und an das betroffene Konto. War das nicht abgesprochen, prüfen Sie bitte unter „Benutzer“ die Konten und unter „Protokoll“ die letzten Änderungen. Bei Verdacht auf Missbrauch beenden Sie unter „Benutzer“ alle Sitzungen und ändern Sie die Passwörter.')
                : __('Diese Nachricht geht an alle Admins. War das nicht abgesprochen, prüfen Sie bitte unter „Protokoll“ die letzten Änderungen und ändern Sie Ihr Passwort.'),
        ]];
    }

    /** Sends to $user (if it has a valid address) in its language; $content returns [subject, lines]. */
    private static function send(array $user, \Closure $content): void
    {
        $to = (string) ($user['email'] ?? '');
        if (!is_valid_email($to)) {
            return;
        }
        [$subject, $body] = AdminI18n::with(AdminI18n::of($user), static function () use ($user, $content): array {
            [$subject, $lines] = $content();
            return [$subject, implode("\n", [__('Hallo {name},', ['name' => self::name($user)]), '', ...$lines])];
        });
        Mailer::send($to, $subject, $body);
    }

    private static function vars(array $vars): array
    {
        return $vars + ['app' => app_name(), 'url' => abs_url('/admin', ['lang' => null])];
    }

    private static function name(array $user): string
    {
        return (string) ($user['display_name'] ?: $user['username']);
    }
}
