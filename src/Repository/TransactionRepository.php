<?php

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    private ?AccountRepository $accountRepository = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    private function getAccountRepository(): AccountRepository
    {
        if ($this->accountRepository === null) {
            $this->accountRepository = $this->getEntityManager()->getRepository(\App\Entity\Account::class);
        }
        return $this->accountRepository;
    }

    public function findByTransactionId(string $transactionId): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.fromAccount', 'fa')
            ->addSelect('fa')
            ->innerJoin('t.toAccount', 'ta')
            ->addSelect('ta')
            ->where('t.transactionId = :transactionId')
            ->setParameter('transactionId', $transactionId)
            ->getQuery()
            ->enableResultCache(300) // Cache for 5 minutes
            ->getOneOrNullResult();
    }

    /**
     * Find all transactions for a specific account (both as sender and receiver).
     * Optimized query: first get account ID, then query transactions directly using indexes.
     * Uses Doctrine QueryBuilder for better maintainability.
     * 
     * @param string $accountNumber The account number
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @param string|null $status Optional status filter
     * @return Transaction[]
     */
    public function findByAccountNumber(
        string $accountNumber,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null
    ): array {
        // First, get the account entity (fast lookup using index on account_number)
        $account = $this->getAccountRepository()->findByAccountNumber($accountNumber);
        
        if ($account === null) {
            return [];
        }
        
        $accountId = $account->getId();
        
        // Query 1: Transactions where account is the sender (uses index on from_account_id)
        $qb1 = $this->createQueryBuilder('t')
            ->innerJoin('t.fromAccount', 'fa')
            ->addSelect('fa')
            ->innerJoin('t.toAccount', 'ta')
            ->addSelect('ta')
            ->where('t.fromAccount = :accountId')
            ->setParameter('accountId', $accountId);
        
        if ($status !== null) {
            $qb1->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }
        
        $transactions1 = $qb1->getQuery()->getResult();
        
        // Query 2: Transactions where account is the receiver (uses index on to_account_id)
        $qb2 = $this->createQueryBuilder('t')
            ->innerJoin('t.fromAccount', 'fa')
            ->addSelect('fa')
            ->innerJoin('t.toAccount', 'ta')
            ->addSelect('ta')
            ->where('t.toAccount = :accountId')
            ->setParameter('accountId', $accountId);
        
        if ($status !== null) {
            $qb2->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }
        
        $transactions2 = $qb2->getQuery()->getResult();
        
        // Merge and deduplicate by transaction ID
        $allTransactions = [];
        $seenIds = [];
        foreach ([...$transactions1, ...$transactions2] as $transaction) {
            $id = $transaction->getId();
            if (!isset($seenIds[$id])) {
                $allTransactions[] = $transaction;
                $seenIds[$id] = true;
            }
        }
        
        // Sort by createdAt DESC
        usort($allTransactions, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });
        
        // Apply pagination
        return array_slice($allTransactions, $offset, $limit);
    }

    /**
     * Count total transactions for a specific account.
     * Optimized: first get account ID, then count using indexes directly.
     * Uses Doctrine QueryBuilder for better maintainability.
     * 
     * @param string $accountNumber The account number
     * @param string|null $status Optional status filter
     * @return int
     */
    public function countByAccountNumber(string $accountNumber, ?string $status = null): int
    {
        // First, get the account entity (fast lookup using index on account_number)
        $account = $this->getAccountRepository()->findByAccountNumber($accountNumber);
        
        if ($account === null) {
            return 0;
        }
        
        $accountId = $account->getId();
        
        // Get all transaction IDs where account is involved (as sender or receiver)
        // Query 1: Transactions where account is the sender
        $qb1 = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.fromAccount = :accountId')
            ->setParameter('accountId', $accountId);
        
        if ($status !== null) {
            $qb1->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }
        
        $result1 = $qb1->getQuery()->getScalarResult();
        $ids1 = array_map(function($row) {
            return $row['id'];
        }, $result1);
        
        // Query 2: Transactions where account is the receiver
        $qb2 = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.toAccount = :accountId')
            ->setParameter('accountId', $accountId);
        
        if ($status !== null) {
            $qb2->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }
        
        $result2 = $qb2->getQuery()->getScalarResult();
        $ids2 = array_map(function($row) {
            return $row['id'];
        }, $result2);
        
        // Merge and count distinct transaction IDs
        $allIds = array_unique(array_merge($ids1, $ids2));
        
        return count($allIds);
    }
}

