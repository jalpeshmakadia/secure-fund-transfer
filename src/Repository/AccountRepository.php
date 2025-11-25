<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findByAccountNumber(string $accountNumber): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.accountNumber = :accountNumber')
            ->setParameter('accountNumber', $accountNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountNumberForUpdate(string $accountNumber): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.accountNumber = :accountNumber')
            ->setParameter('accountNumber', $accountNumber)
            ->getQuery()
            ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Find multiple accounts by their account numbers in a single query.
     * Returns an associative array keyed by account number.
     * 
     * @param string[] $accountNumbers
     * @return array<string, Account>
     */
    public function findByAccountNumbers(array $accountNumbers): array
    {
        if (empty($accountNumbers)) {
            return [];
        }

        $accounts = $this->createQueryBuilder('a')
            ->where('a.accountNumber IN (:accountNumbers)')
            ->setParameter('accountNumbers', $accountNumbers)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($accounts as $account) {
            $result[$account->getAccountNumber()] = $account;
        }

        return $result;
    }
}

