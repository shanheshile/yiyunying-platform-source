<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Trace
{
    private static ?string $id = null;

    public static function id(): string
    {
        if (self::$id === null) {
            self::$id = date('YmdHis') . '-' . bin2hex(random_bytes(6));
        }
        return self::$id;
    }
}
