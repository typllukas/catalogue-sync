<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013135225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the daily sales history and the store stock for replenishment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_store_sales (store_number SMALLINT UNSIGNED NOT NULL, product_id BINARY(16) NOT NULL, sales_date DATE NOT NULL, quantity SMALLINT UNSIGNED NOT NULL, PRIMARY KEY (store_number, product_id, sales_date)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE store_stock (store_number SMALLINT UNSIGNED NOT NULL, product_id BINARY(16) NOT NULL, quantity_on_hand INT UNSIGNED NOT NULL, quantity_on_order INT UNSIGNED NOT NULL, PRIMARY KEY (store_number, product_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_store_sales');
        $this->addSql('DROP TABLE store_stock');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
