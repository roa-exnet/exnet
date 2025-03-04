<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250228164615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE menu_element (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, icon VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, parent_id INTEGER NOT NULL, ruta VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL)');
        $this->addSql('CREATE TABLE menu_element_modulo (menu_element_id INTEGER NOT NULL, modulo_id INTEGER NOT NULL, PRIMARY KEY(menu_element_id, modulo_id), CONSTRAINT FK_7C2C33863EB29EF6 FOREIGN KEY (menu_element_id) REFERENCES menu_element (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7C2C3386C07F55F5 FOREIGN KEY (modulo_id) REFERENCES modulo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7C2C33863EB29EF6 ON menu_element_modulo (menu_element_id)');
        $this->addSql('CREATE INDEX IDX_7C2C3386C07F55F5 ON menu_element_modulo (modulo_id)');
        $this->addSql('CREATE TABLE modulo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) NOT NULL, install_date DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        )');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE menu_element');
        $this->addSql('DROP TABLE menu_element_modulo');
        $this->addSql('DROP TABLE modulo');
    }
}
