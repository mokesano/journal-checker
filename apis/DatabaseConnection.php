<?php

declare(strict_types=1);

/**
 * @file apis/DatabaseConnection.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DatabaseConnection
 * @ingroup apis
 *
 * @brief Handle requests for database connections.
 */

namespace JournalChecker;

use PDO;
use PDOException;

class DatabaseConnection
{
    public static function createFromEnvironment(?string $basePath = null): ?PDO
    {
        self::loadEnv($basePath ?: dirname(__DIR__));

        $driver = getenv('DB_DRIVER') ?: 'mysql';
        $database = getenv('DB_DATABASE') ?: '';
        $username = getenv('DB_USERNAME') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';

        if ($database === '' || $username === '') {
            return null;
        }

        try {
            if ($driver === 'sqlite') {
                $dsn = 'sqlite:' . $database;
            } else {
                $host = getenv('DB_HOST') ?: 'localhost';
                $port = getenv('DB_PORT') ?: '3306';
                $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);
            }

            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException) {
            return null;
        }
    }

    private static function loadEnv(string $basePath): void
    {
        $envFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
