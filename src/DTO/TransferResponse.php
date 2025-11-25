<?php

namespace App\DTO;

/**
 * Data Transfer Object for fund transfer responses.
 */
class TransferResponse
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $status,
        public readonly string $fromAccountNumber,
        public readonly string $toAccountNumber,
        public readonly string $amount,
        public readonly string $fromAccountBalance,
        public readonly string $toAccountBalance,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?string $errorMessage = null
    ) {
    }
}

