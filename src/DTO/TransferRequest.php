<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Data Transfer Object for fund transfer requests.
 */
class TransferRequest
{
    #[Assert\NotBlank(message: 'From account number is required')]
    #[Assert\Length(min: 1, max: 50, minMessage: 'Account number must be at least 1 character', maxMessage: 'Account number cannot exceed 50 characters')]
    public string $fromAccountNumber;

    #[Assert\NotBlank(message: 'To account number is required')]
    #[Assert\Length(min: 1, max: 50, minMessage: 'Account number must be at least 1 character', maxMessage: 'Account number cannot exceed 50 characters')]
    public string $toAccountNumber;

    #[Assert\NotBlank(message: 'Amount is required')]
    #[Assert\GreaterThan(value: 0, message: 'Amount must be greater than zero')]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'Amount must be a valid decimal number with up to 2 decimal places'
    )]
    public string $amount;

    #[Assert\Length(max: 36)]
    public ?string $transactionId = null;
}

