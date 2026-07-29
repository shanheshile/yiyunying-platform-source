<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Pagination
{
    public static function data(array $items, int $total, int $page, int $limit): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
        ];
    }
}
