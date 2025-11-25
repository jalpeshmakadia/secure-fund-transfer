<?php

namespace App\Exception;

/**
 * Exception thrown when a duplicate transaction ID is detected.
 */
class DuplicateTransactionException extends \RuntimeException
{
    public function __construct(
        string $transactionId,
        ?\Throwable $previous = null
    ) {
        $message = sprintf('Transaction with ID %s already exists', $transactionId);
        parent::__construct($message, 0, $previous);
    }
}

