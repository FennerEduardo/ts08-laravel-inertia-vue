<?php

declare(strict_types=1);

namespace App\Runtime;

use PDO;

/** PDO connections from a libpq-style URL: postgres://user:password@host:5432/database[?sslmode=require] */
final class Database
{
    /** libpq TLS parameters passed to the pgsql DSN (sslmode=require encrypts without verifying, as in libpq). */
    private const TLS_PARAMETERS = ['sslmode', 'sslrootcert', 'sslcert', 'sslkey'];

    public static function connect(string $databaseUrl): PDO
    {
        $url = parse_url($databaseUrl);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'], $url['port'] ?? 5432, ltrim($url['path'] ?? '', '/'));
        parse_str($url['query'] ?? '', $query);
        foreach (self::TLS_PARAMETERS as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && preg_match('/^[A-Za-z0-9_.\/-]+$/', $query[$name])) {
                $dsn .= ";{$name}={$query[$name]}";
            }
        }
        return new PDO($dsn, isset($url['user']) ? urldecode($url['user']) : null, isset($url['pass']) ? urldecode($url['pass']) : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @param list<mixed> $params */
    public static function exec(PDO $pdo, string $sql, array $params = []): int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
}
