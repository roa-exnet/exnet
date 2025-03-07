<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250307095218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE menu_element ADD COLUMN nombre VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__menu_element AS SELECT id, icon, type, parent_id, ruta, enabled FROM menu_element');
        $this->addSql('DROP TABLE menu_element');
        $this->addSql('CREATE TABLE menu_element (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, icon VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, parent_id INTEGER NOT NULL, ruta VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO menu_element (id, icon, type, parent_id, ruta, enabled) SELECT id, icon, type, parent_id, ruta, enabled FROM __temp__menu_element');
        $this->addSql('DROP TABLE __temp__menu_element');
    }
}
