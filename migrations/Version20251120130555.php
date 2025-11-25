<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251120130555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts and transactions tables for fund transfer system';
    }

    public function up(Schema $schema): void
    {
        // Create accounts table
        $this->addSql('CREATE TABLE accounts (
            id INT AUTO_INCREMENT NOT NULL,
            account_number VARCHAR(50) NOT NULL,
            balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
            account_holder_name VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            version INT NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_account_number (account_number),
            INDEX idx_account_number (account_number),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create transactions table
        $this->addSql('CREATE TABLE transactions (
            id INT AUTO_INCREMENT NOT NULL,
            transaction_id VARCHAR(36) NOT NULL,
            from_account_id INT NOT NULL,
            to_account_id INT NOT NULL,
            amount NUMERIC(15, 2) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_transaction_id (transaction_id),
            INDEX idx_from_account (from_account_id),
            INDEX idx_to_account (to_account_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_transactions_from_account 
            FOREIGN KEY (from_account_id) REFERENCES accounts (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_transactions_to_account 
            FOREIGN KEY (to_account_id) REFERENCES accounts (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_transactions_from_account');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_transactions_to_account');
        
        // Drop tables
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE accounts');
    }
}
