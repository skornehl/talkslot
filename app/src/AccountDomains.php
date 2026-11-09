<?php
declare(strict_types=1);

namespace App;

/**
 * Mail domains for the addresses of accounts (config allowed_account_domains, expert review P1-4). Summaries,
 * password links and the replies of parents go to these addresses, so a school can require its own mailboxes,
 * where its deletion rules apply. An empty list allows every address. A domain matches exactly and regardless
 * of case: "schule.example.org" allows "a@schule.example.org", but neither "a@lehrer.schule.example.org" nor
 * "a@example.org". A domain with umlauts may be written as it is or as punycode ("xn--…"); it is compared in
 * the ASCII form addresses are stored in (App\Email).
 */
final class AccountDomains
{
    /** @return list<string> the configured domains in lower case, without a leading "@" */
    public static function allowed(): array
    {
        $domains = Config::get('allowed_account_domains', []);
        $domains = array_map(static fn ($d): string => is_string($d) ? self::ascii($d) : '', is_array($domains) ? $domains : []);
        return array_values(array_unique(array_filter($domains, self::valid(...))));
    }

    /**
     * The configured domains for messages: in lower case, umlauts as the admin wrote them (allowed() has the
     * punycode form that addresses are compared in).
     * @return list<string>
     */
    public static function shown(): array
    {
        $domains = Config::get('allowed_account_domains', []);
        $domains = array_map(static fn ($d): string => is_string($d) ? mb_strtolower(ltrim(trim($d), '@'), 'UTF-8') : '', is_array($domains) ? $domains : []);
        return array_values(array_unique(array_filter($domains, self::valid(...))));
    }

    /** May an account use this address? Always without a restriction; with one, only at a listed domain. */
    public static function permits(string $email): bool
    {
        $allowed = self::allowed();
        $at = strrpos($email, '@');
        return !$allowed || ($at !== false && in_array(strtolower(substr($email, $at + 1)), $allowed, true));
    }

    /** The message for an address that is not allowed; null for an allowed or empty one. */
    public static function error(string $email): ?string
    {
        return $email === '' || self::permits($email) ? null
            : __('Bitte eine Adresse bei {domains} angeben: Die Schule erlaubt für Konten nur ihre dienstlichen Adressen.', ['domains' => implode(', ', self::shown())]);
    }

    /**
     * An entry of the list: a domain name with at least one dot (letters, digits, hyphens; umlauts as they are
     * or as punycode), "@" in front allowed.
     */
    public static function valid(string $domain): bool
    {
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/', self::ascii($domain)) === 1;
    }

    /** An entry in the form addresses are compared in: lower case, without "@", umlauts as punycode. */
    private static function ascii(string $domain): string
    {
        return strtolower(Email::domainToAscii(ltrim(trim($domain), '@')) ?? '');
    }
}
