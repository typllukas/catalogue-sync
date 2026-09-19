<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919143512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the deleted product tombstone table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deleted_product (id BINARY(16) NOT NULL, deleted_at DATETIME NOT NULL, INDEX idx_deleted_at (deleted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deleted_product');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
