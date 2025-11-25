<?php

namespace App\Controller;

use App\DTO\TransferRequest;
use App\DTO\TransferResponse;
use App\Exception\AccountNotFoundException;
use App\Exception\DuplicateTransactionException;
use App\Exception\InsufficientFundsException;
use App\Message\TransferMessage;
use App\Service\FundTransferService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API Controller for fund transfer operations.
 */
#[Route('/api/v1', name: 'api_v1_')]
class FundTransferController extends AbstractController
{
    public function __construct(
        private readonly FundTransferService $fundTransferService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    /**
     * Transfer funds between accounts.
     * 
     * POST /api/v1/transfer
     * 
     * Request body:
     * {
     *   "fromAccountNumber": "ACC001",
     *   "toAccountNumber": "ACC002",
     *   "amount": "100.50",
     *   "transactionId": "optional-uuid" // Optional, for idempotency
     * }
     */
    #[Route('/transfer', name: 'transfer', methods: ['POST'])]
    public function transfer(
        Request $httpRequest
    ): JsonResponse {
        // Handle JSON payload - support both with and without Content-Type header
        $content = $httpRequest->getContent();
        if (empty($content)) {
            return $this->json([
                'success' => false,
                'error' => 'Request body is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Try to parse JSON
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid JSON format: ' . json_last_error_msg(),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Create TransferRequest from parsed data
        $request = new TransferRequest();
        $request->fromAccountNumber = $data['fromAccountNumber'] ?? '';
        $request->toAccountNumber = $data['toAccountNumber'] ?? '';
        $request->amount = $data['amount'] ?? '';
        $request->transactionId = $data['transactionId'] ?? null;
        try {
            // Validate request
            $violations = $this->validator->validate($request);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[$violation->getPropertyPath()] = $violation->getMessage();
                }
                
                return $this->json([
                    'success' => false,
                    'errors' => $errors,
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create pending transaction record for idempotency and tracking
            // (duplicate transaction ID check is handled inside createPendingTransaction)
            $transaction = $this->fundTransferService->createPendingTransaction(
                $request->fromAccountNumber,
                $request->toAccountNumber,
                $request->amount,
                $request->transactionId
            );

            // Dispatch message for async processing
            $this->messageBus->dispatch(new TransferMessage(
                $request->fromAccountNumber,
                $request->toAccountNumber,
                $request->amount,
                $transaction->getTransactionId()
            ));

            $this->logger->info('Transfer request queued for async processing', [
                'transaction_id' => $transaction->getTransactionId(),
                'from' => $request->fromAccountNumber,
                'to' => $request->toAccountNumber,
                'amount' => $request->amount,
            ]);

            // Return immediately with pending status
            return $this->json([
                'success' => true,
                'data' => [
                    'transactionId' => $transaction->getTransactionId(),
                    'status' => $transaction->getStatus(),
                    'fromAccountNumber' => $request->fromAccountNumber,
                    'toAccountNumber' => $request->toAccountNumber,
                    'amount' => $request->amount,
                    'message' => 'Transfer request has been queued for processing',
                ],
            ], Response::HTTP_ACCEPTED);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Invalid transfer request', [
                'error' => $e->getMessage(),
                'request' => $request,
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (InsufficientFundsException | AccountNotFoundException $e) {
            $this->logger->warning('Transfer validation failed', [
                'error' => $e->getMessage(),
                'request' => $request,
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (DuplicateTransactionException $e) {
            $this->logger->warning('Duplicate transaction detected', [
                'error' => $e->getMessage(),
                'request' => $request,
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);

        } catch (\RuntimeException $e) {
            $this->logger->error('Transfer failed', [
                'error' => $e->getMessage(),
                'request' => $request,
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during transfer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'An unexpected error occurred. Please try again later.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get transaction status by transaction ID.
     * 
     * GET /api/v1/transaction/{transactionId}
     */
    #[Route('/transaction/{transactionId}', name: 'get_transaction', methods: ['GET'])]
    public function getTransaction(string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->fundTransferService->getTransaction($transactionId);

            if ($transaction === null) {
                return $this->json([
                    'success' => false,
                    'error' => 'Transaction not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $fromAccount = $transaction->getFromAccount();
            $toAccount = $transaction->getToAccount();

            return $this->json([
                'success' => true,
                'data' => [
                    'transactionId' => $transaction->getTransactionId(),
                    'status' => $transaction->getStatus(),
                    'fromAccountNumber' => $fromAccount->getAccountNumber(),
                    'fromAccountHolder' => [
                        'firstName' => $fromAccount->getFirstName(),
                        'lastName' => $fromAccount->getLastName(),
                    ],
                    'toAccountNumber' => $toAccount->getAccountNumber(),
                    'toAccountHolder' => [
                        'firstName' => $toAccount->getFirstName(),
                        'lastName' => $toAccount->getLastName(),
                    ],
                    'amount' => $transaction->getAmount(),
                    'fromAccountBalance' => $fromAccount->getBalance(),
                    'toAccountBalance' => $toAccount->getBalance(),
                    'createdAt' => $transaction->getCreatedAt()->format('c'),
                    'completedAt' => $transaction->getCompletedAt()?->format('c'),
                    'errorMessage' => $transaction->getErrorMessage(),
                ],
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Error retrieving transaction', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'An error occurred while retrieving the transaction',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all transactions for a specific account.
     * 
     * GET /api/v1/account/{accountNumber}/transactions
     * 
     * Query parameters:
     * - limit: Maximum number of results (default: 50, max: 100)
     * - offset: Offset for pagination (default: 0)
     * - status: Optional status filter (pending, completed, failed, reversed)
     */
    #[Route('/account/{accountNumber}/transactions', name: 'get_account_transactions', methods: ['GET'])]
    public function getAccountTransactions(
        string $accountNumber,
        Request $request
    ): JsonResponse {
        try {
            // Get query parameters
            $limit = (int) $request->query->get('limit', 50);
            $offset = (int) $request->query->get('offset', 0);
            $status = $request->query->get('status');

            $result = $this->fundTransferService->getAccountTransactions(
                $accountNumber,
                $limit,
                $offset,
                $status !== '' ? $status : null
            );

            // Format transactions for response
            $transactions = array_map(function ($transaction) use ($accountNumber) {
                $isOutgoing = $transaction->getFromAccount()->getAccountNumber() === $accountNumber;
                $fromAccount = $transaction->getFromAccount();
                $toAccount = $transaction->getToAccount();
                
                return [
                    'transactionId' => $transaction->getTransactionId(),
                    'type' => $isOutgoing ? 'outgoing' : 'incoming',
                    'status' => $transaction->getStatus(),
                    'fromAccountNumber' => $fromAccount->getAccountNumber(),
                    'fromAccountHolder' => [
                        'firstName' => $fromAccount->getFirstName(),
                        'lastName' => $fromAccount->getLastName(),
                    ],
                    'toAccountNumber' => $toAccount->getAccountNumber(),
                    'toAccountHolder' => [
                        'firstName' => $toAccount->getFirstName(),
                        'lastName' => $toAccount->getLastName(),
                    ],
                    'amount' => $transaction->getAmount(),
                    'createdAt' => $transaction->getCreatedAt()->format('c'),
                    'completedAt' => $transaction->getCompletedAt()?->format('c'),
                    'errorMessage' => $transaction->getErrorMessage(),
                ];
            }, $result['transactions']);

            return $this->json([
                'success' => true,
                'data' => [
                    'accountNumber' => $accountNumber,
                    'transactions' => $transactions,
                    'pagination' => [
                        'total' => $result['total'],
                        'limit' => $result['limit'],
                        'offset' => $result['offset'],
                        'hasMore' => ($result['offset'] + $result['limit']) < $result['total'],
                    ],
                ],
            ], Response::HTTP_OK);

        } catch (AccountNotFoundException $e) {
            $this->logger->warning('Account not found for transaction list', [
                'account_number' => $accountNumber,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Invalid request parameters', [
                'account_number' => $accountNumber,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (\Exception $e) {
            $this->logger->error('Error retrieving account transactions', [
                'account_number' => $accountNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'An error occurred while retrieving account transactions',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

