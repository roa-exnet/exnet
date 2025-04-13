<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250409170100 extends AbstractMigration
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
        $this->addSql('CREATE TABLE chat (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , closed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , type VARCHAR(20) NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content CLOB NOT NULL, sent_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , read_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , message_type VARCHAR(50) NOT NULL, metadata CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_FAB3FC161A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FAB3FC161A9A7125 ON chat_message (chat_id)');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , left_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, role VARCHAR(50) NOT NULL, CONSTRAINT FK_E8ED9C891A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E8ED9C891A9A7125 ON chat_participant (chat_id)');
        $this->addSql('CREATE TABLE menu_element (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, icon VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, parent_id INTEGER DEFAULT NULL, ruta VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, nombre VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE TABLE menu_element_modulo (menu_element_id INTEGER NOT NULL, modulo_id INTEGER NOT NULL, PRIMARY KEY(menu_element_id, modulo_id), CONSTRAINT FK_7C2C33863EB29EF6 FOREIGN KEY (menu_element_id) REFERENCES menu_element (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7C2C3386C07F55F5 FOREIGN KEY (modulo_id) REFERENCES modulo (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7C2C33863EB29EF6 ON menu_element_modulo (menu_element_id)');
        $this->addSql('CREATE INDEX IDX_7C2C3386C07F55F5 ON menu_element_modulo (modulo_id)');
        $this->addSql('CREATE TABLE modulo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) NOT NULL, install_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , icon VARCHAR(255) NOT NULL, ruta VARCHAR(255) NOT NULL, estado BOOLEAN NOT NULL)');
        $this->addSql('CREATE TABLE pizza (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
        , password VARCHAR(255) NOT NULL, nombre VARCHAR(255) NOT NULL, apellidos VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , last_login DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, ip_address VARCHAR(45) DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, categoria_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, tipo VARCHAR(20) NOT NULL, anio INTEGER DEFAULT NULL, temporada INTEGER DEFAULT NULL, episodio INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_7CC7DA2C3397707A FOREIGN KEY (categoria_id) REFERENCES categoria (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C3397707A ON video (categoria_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE categoria');
        $this->addSql('DROP TABLE chat');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('DROP TABLE menu_element');
        $this->addSql('DROP TABLE menu_element_modulo');
        $this->addSql('DROP TABLE modulo');
        $this->addSql('DROP TABLE pizza');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE video');
    }
}
