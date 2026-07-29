<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class ApiResponse
{
    public array $body;
    public int $httpStatus;

    public function __construct(array $body, int $httpStatus = 200)
    {
        $this->body = $body;
        $this->httpStatus = $httpStatus;
    }
}
