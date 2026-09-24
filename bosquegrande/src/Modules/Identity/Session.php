<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Identity;

if (!defined('ABSPATH')) exit;

/**
 * Device session: a signed, HttpOnly cookie carrying the constituent source
 * (BosqueGrande or LGL) and its id, valid 12 months. Stateless HMAC over
 * source|id|issued|expiry, keyed from the site keyring plus a pepper option
 * that can be rotated to end every session at once.
 */
final class Session
{
    private const COOKIE = 'bg_session';
    private const LIFETIME = 365 * DAY_IN_SECONDS;

    public function __construct(private \BosqueGrande\Core\Encryption $encryption)
    {
    }

    public function start(int $constituentId, string $source = 'lgl'): bool
    {
        if ($constituentId < 1) return false;
        $source = $source === 'bg' ? 'bg' : 'lgl';
        $issued = time();
        $expires = $issued + self::LIFETIME;
        $payload = $source . '|' . $constituentId . '|' . $issued . '|' . $expires;
        $sig = $this->sign($payload);
        if ($sig === '') return false;
        $cookie = $payload . '|' . $sig;
        $set = setcookie(self::COOKIE, $cookie, [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!$set) return false;
        $_COOKIE[self::COOKIE] = $cookie; // Same-request availability.
        return true;
    }

    /** @return array{source: string, constituent_id: int}|null */
    public function identity(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') return null;
        $parts = explode('|', $raw);
        if (count($parts) !== 5) return null;
        [$source, $id, $issued, $expires, $sig] = $parts;
        if (!in_array($source, ['bg', 'lgl'], true)) return null;
        $payload = "$source|$id|$issued|$expires";
        $expected = $this->sign($payload);
        if ($expected === '' || !hash_equals($expected, $sig)) return null;
        if ((int) $expires < time() || (int) $id < 1) return null;
        return ['source' => $source, 'constituent_id' => (int) $id];
    }

    /** The signed-in constituent id, or null. */
    public function constituentId(): ?int
    {
        $identity = $this->identity();
        return $identity ? $identity['constituent_id'] : null;
    }

    public function end(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    private function sign(string $payload): string
    {
        $root = $this->encryption->signingKey('session');
        if ($root === '') return '';
        $pepper = (string) get_option('bg_session_pepper', '');
        if ($pepper === '') {
            add_option('bg_session_pepper', wp_generate_password(32, false), '', false);
            $pepper = (string) get_option('bg_session_pepper', '');
            // A broken options table must not produce a cookie whose
            // signature changes on every request.
            if ($pepper === '') return '';
        }
        return hash_hmac('sha256', $payload, hash_hmac('sha256', $pepper, $root, true));
    }
}
