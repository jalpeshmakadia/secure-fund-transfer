<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Exception\AccountNotFoundException;
use App\Exception\DuplicateTransactionException;
use App\Exception\InsufficientFundsException;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Service for handling secure fund transfers between accounts.
 * 
 * Features:
 * - Optimistic locking via version field to detect concurrent modifications
 * - Automatic retry mechanism for optimistic locking conflicts
 * - Full transaction rollback on any failure
 * - Comprehensive logging
 */
class FundTransferService
{
    private const MAX_RETRIES = 5; // Maximum retries for optimistic locking conflicts

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Transfer funds from one account to another.
     * This method processes an existing pending transaction.
     * 
     * @param string $fromAccountNumber Source account number
     * @param string $toAccountNumber Destination account number
     * @param string $amount Transfer amount (must be positive)
     * @param string|null $transactionId Transaction ID (should match existing pending transaction)
     * @return Transaction The updated transaction
     * @throws \RuntimeException On validation or transfer failure
     */
    public function transferFunds(
        string $fromAccountNumber,
        string $toAccountNumber,
        string $amount,
        ?string $transactionId = null
    ): Transaction {
        $this->logger->info('Processing fund transfer', [
            'from' => $fromAccountNumber,
            'to' => $toAccountNumber,
            'amount' => $amount,
            'transaction_id' => $transactionId,
        ]);

        // If transaction ID is provided, load existing transaction
        $transaction = null;
        if ($transactionId !== null) {
            $transaction = $this->transactionRepository->findByTransactionId($transactionId);
            if ($transaction === null) {
                throw new \RuntimeException('Transaction not found: ' . $transactionId);
            }
            if ($transaction->getStatus() !== Transaction::STATUS_PENDING) {
                $this->logger->warning('Transaction already processed', [
                    'transaction_id' => $transactionId,
                    'status' => $transaction->getStatus(),
                ]);
                return $transaction;
            }
        }

        $result = $this->executeTransfer($fromAccountNumber, $toAccountNumber, $amount, $transaction);
        
        return $result;
    }

    /**
     * Execute the actual transfer within a database transaction.
     */
    private function executeTransfer(
        string $fromAccountNumber,
        string $toAccountNumber,
        string $amount,
        ?Transaction $existingTransaction = null
    ): Transaction {
        $retries = 0;
        
        while ($retries < self::MAX_RETRIES) {
            try {
                $this->entityManager->beginTransaction();
                
                try {
                    // Load accounts using optimistic locking (version field will detect conflicts)
                    $fromAccount = $this->accountRepository->findByAccountNumber($fromAccountNumber);
                    $toAccount = $this->accountRepository->findByAccountNumber($toAccountNumber);

                    if ($fromAccount === null) {
                        throw new AccountNotFoundException($fromAccountNumber);
                    }

                    if ($toAccount === null) {
                        throw new AccountNotFoundException($toAccountNumber);
                    }

                    // Use existing transaction or create new one
                    if ($existingTransaction !== null) {
                        $transaction = $existingTransaction;
                        // Refresh to get latest state
                        $this->entityManager->refresh($transaction);
                        
                        // Verify transaction is still pending
                        if ($transaction->getStatus() !== Transaction::STATUS_PENDING) {
                            $this->logger->info('Transaction already processed, skipping', [
                                'transaction_id' => $transaction->getTransactionId(),
                                'status' => $transaction->getStatus(),
                            ]);
                            return $transaction;
                        }
                        
                        // Use accounts from the existing transaction
                        $fromAccount = $transaction->getFromAccount();
                        $toAccount = $transaction->getToAccount();
                        
                        // Refresh accounts to get latest state for optimistic locking
                        $this->entityManager->refresh($fromAccount);
                        $this->entityManager->refresh($toAccount);
                    } else {
                        // Create new transaction record
                        $transaction = new Transaction();
                        $transaction->setFromAccount($fromAccount);
                        $transaction->setToAccount($toAccount);
                        $transaction->setAmount($amount);
                        $this->entityManager->persist($transaction);
                    }

                    // Perform the transfer
                    $fromAccount->debit($amount);
                    $toAccount->credit($amount);

                    // Flush to check for optimistic locking conflicts
                    $this->entityManager->flush();
                    
                    // Mark transaction as completed
                    $transaction->setStatus(Transaction::STATUS_COMPLETED);
                    $this->entityManager->flush();

                    $this->entityManager->commit();
                    $transactionCommitted = true;

                } catch (\Exception $e) {
                    // Only rollback if transaction hasn't been committed yet
                    if (!isset($transactionCommitted) || !$transactionCommitted) {
                        try {
                            $this->entityManager->rollback();
                        } catch (\Exception $rollbackException) {
                            // Ignore rollback errors (e.g., no active transaction)
                            $this->logger->warning('Rollback failed', [
                                'error' => $rollbackException->getMessage(),
                            ]);
                        }
                    }
                    throw $e;
                }

                // Post-commit operations (outside transaction try-catch)
                // These should not cause rollback if they fail
                try {
                    // Invalidate account balance cache (no need to refresh entities - they're already up to date)
                    $this->invalidateAccountCache($fromAccountNumber);
                    $this->invalidateAccountCache($toAccountNumber);

                    $this->logger->info('Fund transfer completed successfully', [
                        'transaction_id' => $transaction->getTransactionId(),
                        'from' => $fromAccountNumber,
                        'to' => $toAccountNumber,
                        'amount' => $amount,
                        'from_balance' => $fromAccount->getBalance(),
                        'to_balance' => $toAccount->getBalance(),
                    ]);
                } catch (\Exception $postCommitException) {
                    // Log but don't fail the transfer since DB transaction already succeeded
                    $this->logger->warning('Post-commit operations failed', [
                        'error' => $postCommitException->getMessage(),
                    ]);
                }

                return $transaction;

            } catch (\Doctrine\ORM\OptimisticLockException $e) {
                $retries++;
                $this->logger->warning('Optimistic locking conflict, retrying', [
                    'attempt' => $retries,
                    'max_retries' => self::MAX_RETRIES,
                ]);

                if ($retries >= self::MAX_RETRIES) {
                    $this->logger->error('Max retries reached for optimistic locking', [
                        'from' => $fromAccountNumber,
                        'to' => $toAccountNumber,
                        'amount' => $amount,
                        'transaction_id' => $existingTransaction?->getTransactionId(),
                    ]);
                    
                    // Mark transaction as failed if it exists
                    if ($existingTransaction !== null) {
                        try {
                            $this->entityManager->refresh($existingTransaction);
                            $existingTransaction->setStatus(Transaction::STATUS_FAILED);
                            $existingTransaction->setErrorMessage('Transaction failed due to concurrent modification after ' . self::MAX_RETRIES . ' retries');
                            $this->entityManager->flush();
                        } catch (\Exception $updateException) {
                            $this->logger->error('Failed to update transaction status', [
                                'error' => $updateException->getMessage(),
                            ]);
                        }
                    }
                    
                    throw new \RuntimeException('Transaction failed due to concurrent modification. Please retry.', 0, $e);
                }

                // Exponential backoff before retry
                $delay = min(100000 * pow(2, $retries - 1), 1000000); // 100ms, 200ms, 400ms, 800ms, 1000ms max
                usleep((int)$delay);
                
            } catch (\Exception $e) {
                $this->logger->error('Fund transfer failed', [
                    'from' => $fromAccountNumber,
                    'to' => $toAccountNumber,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Try to mark transaction as failed if it exists
                try {
                    if (isset($transaction)) {
                        if ($transaction->getId() === null) {
                            $this->entityManager->persist($transaction);
                        } else {
                            $this->entityManager->refresh($transaction);
                        }
                        $transaction->setStatus(Transaction::STATUS_FAILED);
                        $transaction->setErrorMessage($e->getMessage());
                        $this->entityManager->flush();
                    } elseif ($existingTransaction !== null) {
                        $this->entityManager->refresh($existingTransaction);
                        $existingTransaction->setStatus(Transaction::STATUS_FAILED);
                        $existingTransaction->setErrorMessage($e->getMessage());
                        $this->entityManager->flush();
                    }
                } catch (\Exception $flushException) {
                    $this->logger->error('Failed to update transaction status', [
                        'error' => $flushException->getMessage(),
                    ]);
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Transfer failed after maximum retries');
    }

    /**
     * Create a pending transaction record for async processing.
     * This ensures idempotency and allows tracking of the transfer request.
     */
    public function createPendingTransaction(
        string $fromAccountNumber,
        string $toAccountNumber,
        string $amount,
        ?string $transactionId = null
    ): Transaction {
        // Validate amount
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be greater than zero');
        }

        // Prevent self-transfer
        if ($fromAccountNumber === $toAccountNumber) {
            throw new \InvalidArgumentException('Cannot transfer funds to the same account');
        }

        // Check for duplicate transaction ID
        if ($transactionId !== null) {
            $existingTransaction = $this->transactionRepository->findByTransactionId($transactionId);
            if ($existingTransaction !== null) {
                throw new DuplicateTransactionException($transactionId);
            }
        }

        // Verify accounts exist - fetch both in a single query for better performance
        $accounts = $this->accountRepository->findByAccountNumbers([$fromAccountNumber, $toAccountNumber]);
        
        $fromAccount = $accounts[$fromAccountNumber] ?? null;
        if ($fromAccount === null) {
            throw new AccountNotFoundException($fromAccountNumber);
        }

        $toAccount = $accounts[$toAccountNumber] ?? null;
        if ($toAccount === null) {
            throw new AccountNotFoundException($toAccountNumber);
        }

        // Create pending transaction record
        $transaction = new Transaction();
        $transaction->setFromAccount($fromAccount);
        $transaction->setToAccount($toAccount);
        $transaction->setAmount($amount);
        $transaction->setStatus(Transaction::STATUS_PENDING);
        
        if ($transactionId !== null) {
            $transaction->setTransactionId($transactionId);
        }

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->logger->info('Pending transaction created', [
            'transaction_id' => $transaction->getTransactionId(),
            'from' => $fromAccountNumber,
            'to' => $toAccountNumber,
            'amount' => $amount,
        ]);

        return $transaction;
    }

    /**
     * Get transaction by ID.
     */
    public function getTransaction(string $transactionId): ?Transaction
    {
        return $this->transactionRepository->findByTransactionId($transactionId);
    }

    /**
     * Get account balance with caching.
     */
    public function getAccountBalance(string $accountNumber): ?string
    {
        $cacheKey = 'account_balance:' . $accountNumber;
        
        return $this->cache->get($cacheKey, function () use ($accountNumber) {
            $account = $this->accountRepository->findByAccountNumber($accountNumber);
            return $account?->getBalance();
        });
    }

    /**
     * Invalidate account balance cache.
     */
    private function invalidateAccountCache(string $accountNumber): void
    {
        $cacheKey = 'account_balance:' . $accountNumber;
        $this->cache->delete($cacheKey);
    }

    /**
     * Get all transactions for a specific account.
     * 
     * @param string $accountNumber The account number
     * @param int $limit Maximum number of results (default: 50, max: 100)
     * @param int $offset Offset for pagination (default: 0)
     * @param string|null $status Optional status filter (pending, completed, failed, reversed)
     * @return array{transactions: Transaction[], total: int, limit: int, offset: int}
     * @throws AccountNotFoundException If account doesn't exist
     */
    public function getAccountTransactions(
        string $accountNumber,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null
    ): array {
        // Validate and limit pagination parameters
        $limit = min(max(1, $limit), 100); // Between 1 and 100
        $offset = max(0, $offset);

        // Validate status if provided
        if ($status !== null && !in_array($status, [
            Transaction::STATUS_PENDING,
            Transaction::STATUS_COMPLETED,
            Transaction::STATUS_FAILED,
            Transaction::STATUS_REVERSED
        ], true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }

        // Fetch transactions (account existence will be checked implicitly if no transactions found)
        $transactions = $this->transactionRepository->findByAccountNumber(
            $accountNumber,
            $limit,
            $offset,
            $status
        );

        // Only verify account exists if we have no transactions and no offset
        // This avoids unnecessary query when we know the account exists from transactions
        if (empty($transactions) && $offset === 0) {
            $account = $this->accountRepository->findByAccountNumber($accountNumber);
            if ($account === null) {
                throw new AccountNotFoundException($accountNumber);
            }
        }

        $total = $this->transactionRepository->countByAccountNumber($accountNumber, $status);

        $this->logger->info('Retrieved account transactions', [
            'account_number' => $accountNumber,
            'count' => count($transactions),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'status_filter' => $status,
        ]);

        return [
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}

