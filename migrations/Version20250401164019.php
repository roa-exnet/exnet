<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250401164019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categoria (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, categoria_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, tipo VARCHAR(20) NOT NULL, anio INTEGER DEFAULT NULL, temporada INTEGER DEFAULT NULL, episodio INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_7CC7DA2C3397707A FOREIGN KEY (categoria_id) REFERENCES categoria (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C3397707A ON video (categoria_id)');
        $this->addSql('ALTER TABLE user ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE categoria');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email, roles, password, nombre, apellidos, created_at, last_login, is_active FROM "user"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
        , password VARCHAR(255) NOT NULL, nombre VARCHAR(255) NOT NULL, apellidos VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , last_login DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO "user" (id, email, roles, password, nombre, apellidos, created_at, last_login, is_active) SELECT id, email, roles, password, nombre, apellidos, created_at, last_login, is_active FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
    }
}
