<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add firstName and lastName columns to accounts table
 */
final class Version20251120140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add firstName and lastName columns to accounts table';
    }

    public function up(Schema $schema): void
    {
        // Add firstName and lastName columns as nullable first
        $this->addSql('ALTER TABLE accounts ADD first_name VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE accounts ADD last_name VARCHAR(50) DEFAULT NULL AFTER first_name');
        
        // Populate firstName and lastName from account_holder_name for existing records
        // Split account_holder_name by space - first word is firstName, rest is lastName
        $this->addSql("
            UPDATE accounts 
            SET 
                first_name = SUBSTRING_INDEX(account_holder_name, ' ', 1),
                last_name = CASE 
                    WHEN LOCATE(' ', account_holder_name) > 0 
                    THEN SUBSTRING(account_holder_name, LOCATE(' ', account_holder_name) + 1)
                    ELSE ''
                END
            WHERE first_name IS NULL OR last_name IS NULL
        ");
        
        // Now make them NOT NULL after populating
        $this->addSql('ALTER TABLE accounts MODIFY first_name VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE accounts MODIFY last_name VARCHAR(50) NOT NULL');
        
        // Drop the account_holder_name column
        $this->addSql('ALTER TABLE accounts DROP COLUMN account_holder_name');
    }

    public function down(Schema $schema): void
    {
        // Restore account_holder_name column
        $this->addSql('ALTER TABLE accounts ADD account_holder_name VARCHAR(100) NOT NULL AFTER balance');
        
        // Populate account_holder_name from firstName and lastName
        $this->addSql("
            UPDATE accounts 
            SET account_holder_name = CONCAT(first_name, ' ', last_name)
        ");
        
        // Remove firstName and lastName columns
        $this->addSql('ALTER TABLE accounts DROP COLUMN first_name');
        $this->addSql('ALTER TABLE accounts DROP COLUMN last_name');
    }
}

