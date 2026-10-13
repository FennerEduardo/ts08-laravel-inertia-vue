<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * HS256 bearer tokens. The tenant comes only from the 'tenant_id' claim of a validated token, never from
 * a header such as X-Tenant-Id, and there is no default tenant.
 */
final class JwtTenant
{
    public const TENANT_CLAIM = 'tenant_id';

    private static function decode(string $part): string|false
    {
        return base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Tenant claim of a valid token (signature, exp, nbf checked), else null. */
    public static function tenantFrom(?string $authorization, ?string $secret): ?string
    {
        if ($secret === null || $secret === '' || $authorization === null || stripos($authorization, 'Bearer ') !== 0) {
            return null;
        }
        $parts = explode('.', trim(substr($authorization, 7)));
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) self::decode($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $signature = self::decode($parts[2]);
        if ($signature === false || !hash_equals(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", $secret, true), $signature)) {
            return null;
        }
        $claims = json_decode((string) self::decode($parts[1]), true);
        if (!is_array($claims)) {
            return null;
        }
        $now = time();
        if (is_numeric($claims['exp'] ?? null) && $now >= $claims['exp']) {
            return null;
        }
        if (is_numeric($claims['nbf'] ?? null) && $now < $claims['nbf']) {
            return null;
        }
        $tenant = $claims[self::TENANT_CLAIM] ?? null;
        return is_string($tenant) && trim($tenant) !== '' ? $tenant : null;
    }

    /** Signs an HS256 token (tests and local tooling; production tokens come from your identity provider). */
    public static function sign(array $claims, string $secret): string
    {
        $head = self::encode('{"alg":"HS256","typ":"JWT"}');
        $body = self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        return "{$head}.{$body}." . self::encode(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
    }
}
