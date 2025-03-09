<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250309094542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat AS SELECT id, name, created_at, closed_at, type, is_active FROM chat');
        $this->addSql('DROP TABLE chat');
        $this->addSql('CREATE TABLE chat (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , closed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , type VARCHAR(20) NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO chat (id, name, created_at, closed_at, type, is_active) SELECT id, name, created_at, closed_at, type, is_active FROM __temp__chat');
        $this->addSql('DROP TABLE __temp__chat');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_message AS SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM chat_message');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content CLOB NOT NULL, sent_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , read_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , message_type VARCHAR(50) NOT NULL, metadata CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_FAB3FC161A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_message (id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata) SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM __temp__chat_message');
        $this->addSql('DROP TABLE __temp__chat_message');
        $this->addSql('CREATE INDEX IDX_FAB3FC161A9A7125 ON chat_message (chat_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_participant AS SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM chat_participant');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , left_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, role VARCHAR(50) NOT NULL, CONSTRAINT FK_E8ED9C891A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_participant (id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role) SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM __temp__chat_participant');
        $this->addSql('DROP TABLE __temp__chat_participant');
        $this->addSql('CREATE INDEX IDX_E8ED9C891A9A7125 ON chat_participant (chat_id)');
        $this->addSql('ALTER TABLE modulo ADD COLUMN icon VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE modulo ADD COLUMN ruta VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE modulo ADD COLUMN estado BOOLEAN NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat AS SELECT id, name, created_at, closed_at, type, is_active FROM chat');
        $this->addSql('DROP TABLE chat');
        $this->addSql('CREATE TABLE chat (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , closed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , type VARCHAR(20) NOT NULL, is_active BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO chat (id, name, created_at, closed_at, type, is_active) SELECT id, name, created_at, closed_at, type, is_active FROM __temp__chat');
        $this->addSql('DROP TABLE __temp__chat');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_message AS SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM chat_message');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id INTEGER NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content CLOB NOT NULL, sent_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , read_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , message_type VARCHAR(50) NOT NULL, metadata CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_FAB3FC161A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_message (id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata) SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM __temp__chat_message');
        $this->addSql('DROP TABLE __temp__chat_message');
        $this->addSql('CREATE INDEX IDX_FAB3FC161A9A7125 ON chat_message (chat_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_participant AS SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM chat_participant');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id INTEGER NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , left_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, role VARCHAR(50) NOT NULL, CONSTRAINT FK_E8ED9C891A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_participant (id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role) SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM __temp__chat_participant');
        $this->addSql('DROP TABLE __temp__chat_participant');
        $this->addSql('CREATE INDEX IDX_E8ED9C891A9A7125 ON chat_participant (chat_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__modulo AS SELECT id, nombre, descripcion, install_date, uninstall_date FROM modulo');
        $this->addSql('DROP TABLE modulo');
        $this->addSql('CREATE TABLE modulo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) NOT NULL, install_date DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('INSERT INTO modulo (id, nombre, descripcion, install_date, uninstall_date) SELECT id, nombre, descripcion, install_date, uninstall_date FROM __temp__modulo');
        $this->addSql('DROP TABLE __temp__modulo');
    }
}
