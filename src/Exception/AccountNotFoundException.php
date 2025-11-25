<?php

namespace App\Exception;

/**
 * Exception thrown when an account is not found.
 */
class AccountNotFoundException extends \RuntimeException
{
    public function __construct(
        string $accountNumber,
        ?\Throwable $previous = null
    ) {
        $message = sprintf('Account not found: %s', $accountNumber);
        parent::__construct($message, 0, $previous);
    }
}

