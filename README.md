# Secure Fund Transfer API

A production-ready, secure API for transferring funds between accounts built with Symfony 7, MySQL, and Redis. This system demonstrates enterprise-level practices for handling financial transactions with high reliability, transaction integrity, and scalability.

## Features

- **Secure Fund Transfers**: Atomic transactions with full ACID compliance
- **Distributed Locking**: Redis-based locking prevents race conditions and concurrent transfer conflicts
- **Optimistic Locking**: Version-based concurrency control for account updates
- **Pessimistic Locking**: Database-level row locking for critical account operations
- **Idempotency**: Support for idempotent transactions via transaction IDs
- **Comprehensive Error Handling**: Custom exceptions with detailed error messages
- **Caching**: Redis caching for account balances to improve performance
- **Comprehensive Testing**: Full integration and functional test coverage
- **Logging**: Detailed logging for all operations and errors
- **API Documentation**: RESTful API with clear request/response formats

## Technology Stack

- **PHP 8.2+**: Modern PHP with strict types and attributes
- **Symfony 7.3**: Latest Symfony framework with modern patterns
- **MySQL 8.0**: Relational database with ACID transactions
- **Redis 7**: Distributed locking and caching layer
- **Doctrine ORM**: Database abstraction and entity management
- **PHPUnit 11**: Comprehensive test suite

## Architecture

### Core Components

1. **FundTransferService**: Business logic for fund transfers with:
   - Distributed locking via Redis
   - Database transaction management
   - Optimistic locking retry mechanism
   - Comprehensive error handling

2. **Entities**:
   - `Account`: Represents bank accounts with balance tracking and version control
   - `Transaction`: Records all fund transfers with status tracking

3. **API Endpoints**:
   - `POST /api/v1/transfer`: Transfer funds between accounts
   - `GET /api/v1/transaction/{transactionId}`: Retrieve transaction status
   - `GET /api/v1/account/{accountNumber}/transactions`: Get all transactions for an account

### Security & Reliability Features

- **Transaction Integrity**: All transfers are wrapped in database transactions
- **Concurrency Control**: Multiple layers of locking (Redis + Database)
- **Deadlock Prevention**: Sorted lock keys prevent circular dependencies
- **Retry Mechanism**: Automatic retry on optimistic locking conflicts
- **Idempotency**: Duplicate transaction IDs are detected and handled gracefully
- **Input Validation**: Comprehensive validation using Symfony Validator
- **Error Recovery**: Failed transactions are properly logged and marked

## Docker Setup

This project includes Docker configuration for easy development and deployment.

### Prerequisites

- Docker Desktop (or Docker Engine + Docker Compose)
- Docker Compose v2.0+

### Quick Start

1. **Build and start all services:**
   ```bash
   docker-compose up -d
   ```

2. **Install Composer dependencies** (if not already installed):
   ```bash
   docker-compose exec php composer install
   ```

3. **Run database migrations:**
   ```bash
   docker-compose exec php php bin/console doctrine:migrations:migrate
   ```

4. **Load database fixtures** (optional):
   ```bash
   docker-compose exec php php bin/console doctrine:fixtures:load
   ```
   
   This will create test accounts:
   - ACC001: John Doe (Balance: 1000.00)
   - ACC002: Jane Smith (Balance: 500.00)
   - ACC003: Bob Johnson (Balance: 2500.00)
   - ACC004: Alice Williams (Balance: 750.00)
   
   **Note**: This command will purge the database by default. Use `--append` to add fixtures without clearing existing data:
   ```bash
   docker-compose exec php php bin/console doctrine:fixtures:load --append
   ```

5. **Access the application:**
   - API: http://localhost:8000

### Services

- **php**: PHP 8.2-FPM with required extensions
- **nginx**: Web server (port 8000)
- **database**: MySQL 8.0 (port 3306)
- **redis**: Redis 7 (port 6379)

### Environment Variables

Create a `.env` file in the project root with the following variables:

```env
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=your-secret-key-here
APP_PORT=8000

MYSQL_VERSION=8.0
MYSQL_DATABASE=app
MYSQL_USER=app
MYSQL_PASSWORD=!ChangeMe!
MYSQL_ROOT_PASSWORD=rootpassword
MYSQL_PORT=3306

REDIS_VERSION=7-alpine
REDIS_PORT=6379
```

### Common Commands

#### Run Symfony Console Commands
```bash
docker-compose exec php php bin/console <command>
```

#### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f php
docker-compose logs -f nginx
docker-compose logs -f database
```

#### Stop Services
```bash
docker-compose down
```

#### Stop and Remove Volumes
```bash
docker-compose down -v
```

#### Rebuild Containers
```bash
docker-compose build --no-cache
docker-compose up -d
```

#### Access PHP Container Shell
```bash
docker-compose exec php sh
```

#### Access MySQL
```bash
docker-compose exec database mysql -u app -p
# Password: !ChangeMe! (or your MYSQL_PASSWORD)
```

#### Access Redis CLI
```bash
docker-compose exec redis redis-cli
```

### Development

The project files are mounted as volumes, so changes to your code will be reflected immediately. However, you may need to clear the cache:

```bash
docker-compose exec php php bin/console cache:clear
```

### Production

For production deployment:

1. Set `APP_ENV=prod` and `APP_DEBUG=0` in your `.env` file
2. Update `APP_SECRET` to a strong random value
3. Rebuild the containers:
   ```bash
   docker-compose build --no-cache
   docker-compose up -d
   ```
4. Run migrations:
   ```bash
   docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction
   ```

### Troubleshooting

#### Permission Issues
If you encounter permission issues with the `var/` directory:
```bash
docker-compose exec php chmod -R 777 var/
```

#### Database Connection Issues
Ensure the database service is healthy:
```bash
docker-compose ps
```
Wait for the database to be fully started before running migrations.

#### Port Conflicts
If ports 8000, 3306, or 6379 are already in use, modify the port mappings in `docker-compose.yaml` or set different values in your `.env` file.

## API Documentation

### Transfer Funds

Transfer funds from one account to another.

**Endpoint**: `POST /api/v1/transfer`

**Request Body**:
```json
{
  "fromAccountNumber": "ACC001",
  "toAccountNumber": "ACC002",
  "amount": "100.50",
  "transactionId": "optional-uuid-v4"  // Optional, for idempotency
}
```

**Success Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "transactionId": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "fromAccountNumber": "ACC001",
    "toAccountNumber": "ACC002",
    "amount": "100.50",
    "fromAccountBalance": "899.50",
    "toAccountBalance": "600.50",
    "createdAt": "2024-11-20T13:05:55+00:00",
    "completedAt": "2024-11-20T13:05:55+00:00"
  }
}
```

**Error Responses**:

- **400 Bad Request**: Validation errors
  ```json
  {
    "success": false,
    "errors": {
      "fromAccountNumber": "From account number is required",
      "amount": "Amount must be greater than zero"
    }
  }
  ```

- **422 Unprocessable Entity**: Business logic errors
  ```json
  {
    "success": false,
    "error": "Insufficient funds in account ACC001. Requested: 200.00, Available: 100.00"
  }
  ```

### Get Transaction Status

Retrieve the status of a transaction by its ID.

**Endpoint**: `GET /api/v1/transaction/{transactionId}`

**Success Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "transactionId": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "fromAccountNumber": "ACC001",
    "toAccountNumber": "ACC002",
    "amount": "100.50",
    "fromAccountBalance": "899.50",
    "toAccountBalance": "600.50",
    "createdAt": "2024-11-20T13:05:55+00:00",
    "completedAt": "2024-11-20T13:05:55+00:00",
    "errorMessage": null
  }
}
```

**Error Response** (404 Not Found):
```json
{
  "success": false,
  "error": "Transaction not found"
}
```

### Get Account Transactions

Retrieve all transactions for a specific account with pagination and optional status filtering.

**Endpoint**: `GET /api/v1/account/{accountNumber}/transactions`

**Query Parameters**:
- `limit` (optional): Maximum number of results to return (default: 50, max: 100)
- `offset` (optional): Number of results to skip for pagination (default: 0)
- `status` (optional): Filter transactions by status (`pending`, `completed`, `failed`, `reversed`)

**Example Request**:
```
GET /api/v1/account/ACC001/transactions?limit=20&offset=0&status=completed
```

**Success Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "accountNumber": "ACC001",
    "transactions": [
      {
        "transactionId": "550e8400-e29b-41d4-a716-446655440000",
        "type": "outgoing",
        "status": "completed",
        "fromAccountNumber": "ACC001",
        "fromAccountHolder": {
          "firstName": "John",
          "lastName": "Doe"
        },
        "toAccountNumber": "ACC002",
        "toAccountHolder": {
          "firstName": "Jane",
          "lastName": "Smith"
        },
        "amount": "100.50",
        "createdAt": "2024-11-20T13:05:55+00:00",
        "completedAt": "2024-11-20T13:05:55+00:00",
        "errorMessage": null
      },
      {
        "transactionId": "660e8400-e29b-41d4-a716-446655440001",
        "type": "incoming",
        "status": "completed",
        "fromAccountNumber": "ACC003",
        "fromAccountHolder": {
          "firstName": "Bob",
          "lastName": "Johnson"
        },
        "toAccountNumber": "ACC001",
        "toAccountHolder": {
          "firstName": "John",
          "lastName": "Doe"
        },
        "amount": "250.00",
        "createdAt": "2024-11-20T12:30:00+00:00",
        "completedAt": "2024-11-20T12:30:01+00:00",
        "errorMessage": null
      }
    ],
    "pagination": {
      "total": 15,
      "limit": 20,
      "offset": 0,
      "hasMore": false
    }
  }
}
```

**Response Fields**:
- `type`: Either `"outgoing"` (transaction from this account) or `"incoming"` (transaction to this account)
- `pagination.hasMore`: Indicates if there are more results available

**Error Responses**:

- **400 Bad Request**: Invalid query parameters
  ```json
  {
    "success": false,
    "error": "Limit cannot exceed 100"
  }
  ```

- **404 Not Found**: Account does not exist
  ```json
  {
    "success": false,
    "error": "Account not found"
  }
  ```

## Testing

### Run Tests

```bash
# Run all tests
docker-compose exec php php bin/phpunit

# Run specific test suite
docker-compose exec php php bin/phpunit tests/Integration
docker-compose exec php php bin/phpunit tests/Functional

# Run with coverage (requires Xdebug)
docker-compose exec php php bin/phpunit --coverage-html coverage/
```

### Test Coverage

The test suite includes:

- **Integration Tests** (`tests/Integration/`):
  - Successful transfers
  - Insufficient funds handling
  - Account not found scenarios
  - Self-transfer prevention
  - Idempotency with transaction IDs
  - Concurrent transfer handling
  - Decimal precision handling

- **Functional Tests** (`tests/Functional/`):
  - API endpoint testing
  - Request validation
  - Error response formats
  - Transaction retrieval

## Database Schema

### Accounts Table

```sql
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    version INT NOT NULL DEFAULT 0,
    INDEX idx_account_number (account_number)
);
```

### Transactions Table

```sql
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(36) UNIQUE NOT NULL,
    from_account_id INT NOT NULL,
    to_account_id INT NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message TEXT,
    created_at DATETIME NOT NULL,
    completed_at DATETIME,
    FOREIGN KEY (from_account_id) REFERENCES accounts(id),
    FOREIGN KEY (to_account_id) REFERENCES accounts(id),
    INDEX idx_from_account (from_account_id),
    INDEX idx_to_account (to_account_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);
```

## Performance Considerations

### High-Load Scenarios

The system is designed to handle high loads through:

1. **Distributed Locking**: Redis locks prevent database contention
2. **Pessimistic Locking**: Database-level locks ensure data consistency
3. **Optimistic Locking**: Version-based concurrency with automatic retries
4. **Caching**: Account balances cached in Redis for read operations
5. **Indexed Queries**: All lookup queries use proper database indexes
6. **Connection Pooling**: Doctrine connection pooling for database efficiency

### Scalability

- **Horizontal Scaling**: Stateless API design allows multiple instances
- **Database Sharding**: Schema supports future sharding strategies
- **Cache Invalidation**: Automatic cache invalidation on balance updates
- **Lock Timeout**: Configurable lock timeouts prevent deadlocks

## Error Handling

### Custom Exceptions

- `InsufficientFundsException`: Thrown when account balance is insufficient
- `AccountNotFoundException`: Thrown when account doesn't exist
- `DuplicateTransactionException`: Thrown for duplicate transaction IDs

### Logging

All operations are logged with appropriate levels:
- **INFO**: Successful transfers, idempotent requests
- **WARNING**: Lock acquisition failures, retry attempts
- **ERROR**: Transfer failures, system errors

Logs are written to `var/log/{environment}.log`.

## Development

### Code Quality

- **PSR-12**: Code follows PSR-12 coding standards
- **Type Hints**: Strict types and return types throughout
- **Docblocks**: Comprehensive PHPDoc comments
- **SOLID Principles**: Service-oriented architecture

### Code Organization

```
src/
├── Controller/          # API controllers
├── DTO/                # Data transfer objects
├── Entity/             # Doctrine entities
├── Exception/          # Custom exceptions
├── Repository/         # Data access layer
└── Service/            # Business logic

tests/
├── Functional/         # API endpoint tests
└── Integration/        # Service layer tests
```

## Production Deployment

### Environment Variables

Ensure the following are set in production:

- `APP_ENV=prod`
- `APP_DEBUG=false`
- `APP_SECRET`: Strong, randomly generated secret
- `DATABASE_URL`: Production database connection
- `REDIS_URL`: Production Redis connection

### Security Checklist

- [ ] Change `APP_SECRET` to a strong random value
- [ ] Use HTTPS for all API communications
- [ ] Implement rate limiting
- [ ] Add authentication/authorization
- [ ] Enable database query logging in production
- [ ] Set up monitoring and alerting
- [ ] Configure backup strategy
- [ ] Review and restrict database user permissions

### Performance Tuning

- Enable Doctrine query result caching
- Configure Redis persistence
- Tune MySQL connection pool size
- Set appropriate lock TTL values
- Monitor slow query logs

## License

Proprietary - All rights reserved

## Author

Built as a demonstration of production-ready financial application development practices.
