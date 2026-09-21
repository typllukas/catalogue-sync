<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921094207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the synchronisation outbox and the drain run log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_sync_outbox (id BINARY(16) NOT NULL, index_name VARCHAR(64) NOT NULL, product_id BINARY(16) NOT NULL, scope_mask INT NOT NULL, operation VARCHAR(16) NOT NULL, marked_at DATETIME(3) NOT NULL, INDEX idx_product_sync_outbox_marked_at (marked_at), UNIQUE INDEX uniq_product_sync_outbox (index_name, product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_sync_run (id BINARY(16) NOT NULL, ran_at DATETIME NOT NULL, written_product_count INT NOT NULL, unchanged_product_count INT NOT NULL, elapsed_milliseconds INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_sync_outbox');
        $this->addSql('DROP TABLE product_sync_run');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
