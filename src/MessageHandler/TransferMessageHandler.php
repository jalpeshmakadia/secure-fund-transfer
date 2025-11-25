<?php

namespace App\MessageHandler;

use App\Message\TransferMessage;
use App\Service\FundTransferService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for processing fund transfer messages asynchronously.
 */
#[AsMessageHandler]
class TransferMessageHandler
{
    public function __construct(
        private readonly FundTransferService $fundTransferService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(TransferMessage $message): void
    {
        $this->logger->info('Processing transfer message', [
            'from' => $message->getFromAccountNumber(),
            'to' => $message->getToAccountNumber(),
            'amount' => $message->getAmount(),
            'transaction_id' => $message->getTransactionId(),
        ]);

        try {
            // Process the transfer using the transaction ID from the pending transaction
            $transaction = $this->fundTransferService->transferFunds(
                $message->getFromAccountNumber(),
                $message->getToAccountNumber(),
                $message->getAmount(),
                $message->getTransactionId()
            );

            $this->logger->info('Transfer message processed successfully', [
                'transaction_id' => $transaction->getTransactionId(),
                'status' => $transaction->getStatus(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process transfer message', [
                'from' => $message->getFromAccountNumber(),
                'to' => $message->getToAccountNumber(),
                'amount' => $message->getAmount(),
                'transaction_id' => $message->getTransactionId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism or move to failed queue
            throw $e;
        }
    }
}

