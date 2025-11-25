<?php

namespace App\Tests\Functional;

use App\Entity\Account;
use App\Tests\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for FundTransferController API endpoints.
 */
class FundTransferControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();
    }

    public function testSuccessfulTransfer(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC002',
                'amount' => '250.00',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('completed', $data['data']['status']);
        $this->assertEquals('250.00', $data['data']['amount']);
        $this->assertEquals('750.00', $data['data']['fromAccountBalance']);
        $this->assertEquals('750.00', $data['data']['toAccountBalance']);
        $this->assertArrayHasKey('transactionId', $data['data']);
    }

    public function testTransferWithTransactionId(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $transactionId = 'test-tx-' . uniqid();

        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC002',
                'amount' => '100.00',
                'transactionId' => $transactionId,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($transactionId, $data['data']['transactionId']);
    }

    public function testInsufficientFunds(): void
    {
        $this->createAccount('ACC001', '100.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC002',
                'amount' => '200.00',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Insufficient funds', $data['error']);
    }

    public function testAccountNotFound(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');

        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC999',
                'amount' => '100.00',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Account not found', $data['error']);
    }

    public function testValidationErrors(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => '',
                'toAccountNumber' => 'ACC002',
                'amount' => '-10.00',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testGetTransaction(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');
        $this->createAccount('ACC002', '500.00', 'Jane Smith');

        // First create a transaction
        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC002',
                'amount' => '100.00',
            ])
        );

        $createResponse = json_decode($this->client->getResponse()->getContent(), true);
        $transactionId = $createResponse['data']['transactionId'];

        // Then retrieve it
        $this->client->request('GET', '/api/v1/transaction/' . $transactionId);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals($transactionId, $data['data']['transactionId']);
        $this->assertEquals('completed', $data['data']['status']);
    }

    public function testGetNonExistentTransaction(): void
    {
        $this->client->request('GET', '/api/v1/transaction/non-existent-id');

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Transaction not found', $data['error']);
    }

    public function testInvalidJson(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid json'
        );

        $response = $this->client->getResponse();
        // Should return 400 or 422
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testSelfTransfer(): void
    {
        $this->createAccount('ACC001', '1000.00', 'John Doe');

        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fromAccountNumber' => 'ACC001',
                'toAccountNumber' => 'ACC001',
                'amount' => '100.00',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('same account', $data['error']);
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

    private function clearDatabase(): void
    {
        $em = $this->em;
        
        // Disable foreign key checks temporarily
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        
        // Clear tables
        $em->getConnection()->executeStatement('TRUNCATE TABLE transactions');
        $em->getConnection()->executeStatement('TRUNCATE TABLE accounts');
        
        // Re-enable foreign key checks
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $em->clear();
    }
}

