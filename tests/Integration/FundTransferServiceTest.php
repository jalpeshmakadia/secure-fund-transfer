<?php

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Exception\AccountNotFoundException;
use App\Exception\DuplicateTransactionException;
use App\Exception\InsufficientFundsException;
use App\Repository\AccountRepository;
use App\Service\FundTransferService;
use App\Tests\TestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration tests for FundTransferService.
 */
class FundTransferServiceTest extends TestCase
{
    private FundTransferService $service;
    private EntityManagerInterface $em;
    private AccountRepository $accountRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = self::getContainer()->get(FundTransferService::class);
        $this->em = $this->getEntityManager();
        $this->accountRepository = $this->em->getRepository(Account::class);
        $this->clearDatabase();
    }

    public function testSuccessfulTransfer(): void
    {
        // Create test accounts
        $fromAccount = $this->createAccount('ACC001', '1000.00', 'John Doe');
        $toAccount = $this->createAccount('ACC002', '500.00', 'Jane Smith');

        // Perform transfer
        $transaction = $this->service->transferFunds('ACC001', 'ACC002', '250.00');

        // Verify transaction
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEquals(Transaction::STATUS_COMPLETED, $transaction->getStatus());
        $this->assertEquals('250.00', $transaction->getAmount());
        $this->assertNotNull($transaction->getCompletedAt());

        // Refresh accounts from database
        $this->em->refresh($fromAccount);
        $this->em->refresh($toAccount);

        // Verify balances
        $this->assertEquals('750.00', $fromAccount->getBalance());
        $this->assertEquals('750.00', $toAccount->getBalance());
    }

    public function testInsufficientFunds(): void
    {
        $fromAccount = $this->createAccount('ACC001', '100.00', 'John Doe');
        $toAccount = $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds');

        $this->service->transferFunds('ACC001', 'ACC002', '200.00');
    }

    public function testAccountNotFound(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');

        $this->expectException(AccountNotFoundException::class);
        $this->expectExceptionMessage('Account not found: ACC999');

        $this->service->transferFunds('ACC001', 'ACC999', '100.00');
    }

    public function testSelfTransfer(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transfer funds to the same account');

        $this->service->transferFunds('ACC001', 'ACC001', '100.00');
    }

    public function testInvalidAmount(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transfer amount must be greater than zero');

        $this->service->transferFunds('ACC001', 'ACC002', '0.00');
    }

    public function testNegativeAmount(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transferFunds('ACC001', 'ACC002', '-10.00');
    }

    public function testIdempotencyWithSameTransactionId(): void
    {
        $fromAccount = $this->createAccount('ACC001', '1000.00', 'John Doe');
        $toAccount = $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $transactionId = 'test-tx-' . uniqid();

        // First transfer
        $transaction1 = $this->service->transferFunds('ACC001', 'ACC002', '100.00', $transactionId);
        
        // Second transfer with same ID should return the same transaction
        $transaction2 = $this->service->transferFunds('ACC001', 'ACC002', '100.00', $transactionId);

        $this->assertEquals($transaction1->getId(), $transaction2->getId());
        $this->assertEquals($transaction1->getTransactionId(), $transaction2->getTransactionId());

        // Verify balance was only debited once
        $this->em->refresh($fromAccount);
        $this->assertEquals('900.00', $fromAccount->getBalance());
    }

    public function testDuplicateTransactionIdWithPendingStatus(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $transactionId = 'test-tx-' . uniqid();

        // Create a pending transaction manually
        $transaction = new Transaction();
        $transaction->setTransactionId($transactionId);
        $transaction->setFromAccount($this->accountRepository->findByAccountNumber('ACC001'));
        $transaction->setToAccount($this->accountRepository->findByAccountNumber('ACC002'));
        $transaction->setAmount('100.00');
        $transaction->setStatus(Transaction::STATUS_PENDING);
        
        $this->em->persist($transaction);
        $this->em->flush();

        $this->expectException(DuplicateTransactionException::class);
        $this->service->transferFunds('ACC001', 'ACC002', '100.00', $transactionId);
    }

    public function testConcurrentTransfers(): void
    {
        $fromAccount = $this->createAccount('ACC001', '1000.00', 'John Doe');
        $toAccount = $this->createAccount('ACC002', '500.00', 'Jane Smith');

        // Simulate concurrent transfers (they should be serialized by locks)
        $results = [];
        $errors = [];

        // Use a simple approach - sequential execution with locks
        // In real concurrent scenario, locks would prevent race conditions
        try {
            $t1 = $this->service->transferFunds('ACC001', 'ACC002', '100.00');
            $t2 = $this->service->transferFunds('ACC001', 'ACC002', '200.00');
            $results[] = $t1;
            $results[] = $t2;
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        // Both should succeed
        $this->assertCount(2, $results);
        $this->assertEmpty($errors);

        // Verify final balances
        $this->em->refresh($fromAccount);
        $this->em->refresh($toAccount);
        $this->assertEquals('700.00', $fromAccount->getBalance());
        $this->assertEquals('800.00', $toAccount->getBalance());
    }

    public function testGetTransaction(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $transaction = $this->service->transferFunds('ACC001', 'ACC002', '100.00');
        $transactionId = $transaction->getTransactionId();

        $retrieved = $this->service->getTransaction($transactionId);

        $this->assertNotNull($retrieved);
        $this->assertEquals($transaction->getId(), $retrieved->getId());
        $this->assertEquals($transactionId, $retrieved->getTransactionId());
    }

    public function testGetNonExistentTransaction(): void
    {
        $result = $this->service->getTransaction('non-existent-id');
        $this->assertNull($result);
    }

    public function testPrecisionHandling(): void
    {
        $fromAccount = $this->createAccount('ACC001', '1000.999', 'John Doe');
        $toAccount = $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $transaction = $this->service->transferFunds('ACC001', 'ACC002', '0.01');

        $this->em->refresh($fromAccount);
        $this->em->refresh($toAccount);

        // Should handle decimal precision correctly
        $this->assertTrue(
            bccomp($fromAccount->getBalance(), '1000.99', 2) >= 0,
            'From account balance should be at least 1000.99'
        );
    }

    /**
     * Helper method to create test accounts.
     */
    private function createAccount(string $accountNumber, string $balance, string $holderName): Account
    {
        // Split holderName into firstName and lastName
        $nameParts = explode(' ', $holderName, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $account = new Account();
        $account->setAccountNumber($accountNumber);
        $account->setBalance($balance);
        $account->setFirstName($firstName);
        $account->setLastName($lastName);

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}

