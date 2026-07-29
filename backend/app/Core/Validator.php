<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Validator
{
    public static function required(array $data, array $fields): void
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[$field] = '该字段不能为空';
                continue;
            }
            $value = $data[$field];
            if ($value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && $value === [])) {
                $errors[$field] = '该字段不能为空';
            }
        }
        if ($errors !== []) {
            throw new HttpException('请求参数不完整', 0, 422, ['errors' => $errors]);
        }
    }

    public static function string($value, string $field, int $min, int $max): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new HttpException("{$field} 必须是字符串", 0, 422);
        }
        $value = trim((string) $value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new HttpException("{$field} 长度必须在 {$min}-{$max} 个字符之间", 0, 422);
        }
        return $value;
    }

    public static function integer($value, string $field, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new HttpException("{$field} 必须是整数", 0, 422);
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new HttpException("{$field} 必须在 {$min}-{$max} 之间", 0, 422);
        }
        return $value;
    }

    public static function boolean($value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }
        throw new HttpException("{$field} 必须是布尔值", 0, 422);
    }

    public static function nullableDateTime($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new HttpException("{$field} 不是有效日期时间", 0, 422);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}
