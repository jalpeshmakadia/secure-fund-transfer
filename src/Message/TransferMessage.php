<?php

namespace App\Message;

/**
 * Message for asynchronous fund transfer processing.
 */
class TransferMessage
{
    public function __construct(
        private readonly string $fromAccountNumber,
        private readonly string $toAccountNumber,
        private readonly string $amount,
        private readonly ?string $transactionId = null
    ) {
    }

    public function getFromAccountNumber(): string
    {
        return $this->fromAccountNumber;
    }

    public function getToAccountNumber(): string
    {
        return $this->toAccountNumber;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }
}

