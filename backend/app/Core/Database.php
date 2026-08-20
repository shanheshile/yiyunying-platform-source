<?php
declare(strict_types=1);

namespace Yiyunying\Core;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) config('database.host');
        $port = (int) config('database.port');
        $name = (string) config('database.name');
        $charset = (string) config('database.charset', 'utf8mb4');
        $collation = (string) config('database.collation', 'utf8mb4_unicode_ci');
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $charset) !== 1
            || preg_match('/\A[a-zA-Z0-9_]+\z/', $collation) !== 1) {
            throw new \RuntimeException('Invalid database charset or collation configuration');
        }
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $initCommand = "SET NAMES {$charset} COLLATE {$collation}";
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[(int) constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = $initCommand;
        }
        self::$pdo = new PDO(
            $dsn,
            (string) config('database.user'),
            (string) config('database.password'),
            $options
        );
        // Keep the invariant even on PDO builds that do not expose MYSQL_ATTR_INIT_COMMAND.
        self::$pdo->exec($initCommand);
        return self::$pdo;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);
        return (int) self::connection()->lastInsertId();
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($pdo);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
