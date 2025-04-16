<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250416151957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__modulo AS SELECT id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado FROM modulo');
        $this->addSql('DROP TABLE modulo');
        $this->addSql('CREATE TABLE modulo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) NOT NULL, install_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , icon VARCHAR(255) NOT NULL, ruta VARCHAR(255) NOT NULL, token VARCHAR(255) DEFAULT NULL, estado BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO modulo (id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado) SELECT id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado FROM __temp__modulo');
        $this->addSql('DROP TABLE __temp__modulo');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__modulo AS SELECT id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado FROM modulo');
        $this->addSql('DROP TABLE modulo');
        $this->addSql('CREATE TABLE modulo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) NOT NULL, install_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , icon VARCHAR(255) NOT NULL, ruta VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, estado BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO modulo (id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado) SELECT id, nombre, descripcion, install_date, uninstall_date, icon, ruta, token, estado FROM __temp__modulo');
        $this->addSql('DROP TABLE __temp__modulo');
    }
}
