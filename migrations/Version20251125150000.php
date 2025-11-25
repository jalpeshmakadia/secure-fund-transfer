<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization: Add composite indexes for better query performance
 */
final class Version20251125150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes for better query performance on transactions table';
    }

    public function up(Schema $schema): void
    {
        // Composite index for from_account_id + status + created_at (for outgoing transactions)
        $this->addSql('CREATE INDEX idx_from_account_status_created ON transactions (from_account_id, status, created_at DESC)');
        
        // Composite index for to_account_id + status + created_at (for incoming transactions)
        $this->addSql('CREATE INDEX idx_to_account_status_created ON transactions (to_account_id, status, created_at DESC)');
        
        // Index on transaction_id for faster lookups (if not already unique indexed)
        // Note: transaction_id already has UNIQUE INDEX, but adding explicit index for clarity
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_from_account_status_created ON transactions');
        $this->addSql('DROP INDEX idx_to_account_status_created ON transactions');
    }
}

