<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918100128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the brand, category and product tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE brand (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1C52F9585E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, slug_path VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_64C19C19391922D (slug_path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id BINARY(16) NOT NULL, sku VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(2000) NOT NULL, ean VARCHAR(13) NOT NULL, price_in_minor_units INT NOT NULL, stock_quantity INT NOT NULL, visible TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, brand_id BINARY(16) NOT NULL, category_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_D34A04ADF9038C4 (sku), UNIQUE INDEX UNIQ_D34A04AD67B1C660 (ean), INDEX IDX_D34A04AD44F5D008 (brand_id), INDEX IDX_D34A04AD12469DE2 (category_id), INDEX idx_product_updated_at (updated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD44F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD44F5D008');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('DROP TABLE brand');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE product');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
