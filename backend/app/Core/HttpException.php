<?php
declare(strict_types=1);

namespace Yiyunying\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public int $apiCode;
    public int $httpStatus;
    public array $data;

    public function __construct(string $message, int $apiCode = 0, int $httpStatus = 422, array $data = [])
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }
}
