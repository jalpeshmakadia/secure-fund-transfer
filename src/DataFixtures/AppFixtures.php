<?php

namespace App\DataFixtures;

use App\Entity\Account;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $accounts = [
            [
                'accountNumber' => 'ACC001',
                'balance' => '1000.00',
                'firstName' => 'John',
                'lastName' => 'Doe',
            ],
            [
                'accountNumber' => 'ACC002',
                'balance' => '500.00',
                'firstName' => 'Jane',
                'lastName' => 'Smith',
            ],
            [
                'accountNumber' => 'ACC003',
                'balance' => '2500.00',
                'firstName' => 'Bob',
                'lastName' => 'Johnson',
            ],
            [
                'accountNumber' => 'ACC004',
                'balance' => '750.00',
                'firstName' => 'Alice',
                'lastName' => 'Williams',
            ],
        ];

        foreach ($accounts as $accountData) {
            $account = new Account();
            $account->setAccountNumber($accountData['accountNumber']);
            $account->setBalance($accountData['balance']);
            $account->setFirstName($accountData['firstName']);
            $account->setLastName($accountData['lastName']);

            $manager->persist($account);
        }

        $manager->flush();
    }
}
