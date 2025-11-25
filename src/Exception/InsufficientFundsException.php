<?php

namespace App\Exception;

/**
 * Exception thrown when an account has insufficient funds for a transfer.
 */
class InsufficientFundsException extends \RuntimeException
{
    public function __construct(
        string $accountNumber,
        string $requestedAmount,
        string $availableBalance,
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            'Insufficient funds in account %s. Requested: %s, Available: %s',
            $accountNumber,
            $requestedAmount,
            $availableBalance
        );
        parent::__construct($message, 0, $previous);
    }
}

