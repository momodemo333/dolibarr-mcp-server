<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * Carries a stable SCREAMING_SNAKE error code alongside the message.
 *
 * The native Exception::getCode() is typed mixed and conventionally an int,
 * so the stable code is exposed through code() instead.
 */
class SqlValidationException extends \RuntimeException
{
    private string $stableCode;

    public function __construct(string $stableCode, string $message)
    {
        parent::__construct($message);
        $this->stableCode = $stableCode;
    }

    public function code(): string
    {
        return $this->stableCode;
    }
}
