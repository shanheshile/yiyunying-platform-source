<?php
declare(strict_types=1);

namespace Yiyunying\Core;

/** Test-only query spy for the upload security contract. */
final class Database
{
    public static array $uploadRows = [];
    public static int $allCalls = 0;
    /** @var null|callable(string,array):array */
    public static $allHandler = null;

    public static function all(string $sql, array $params = []): array
    {
        self::$allCalls++;
        if (is_callable(self::$allHandler)) return (self::$allHandler)($sql, $params);
        return self::$uploadRows;
    }
}
