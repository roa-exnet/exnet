<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250413171521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
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
        $this->addSql('CREATE TEMPORARY TABLE __temp__menu_element_modulo AS SELECT menu_element_id, modulo_id FROM menu_element_modulo');
        $this->addSql('DROP TABLE menu_element_modulo');
        $this->addSql('CREATE TABLE menu_element_modulo (menu_element_id INTEGER NOT NULL, modulo_id INTEGER NOT NULL, PRIMARY KEY(menu_element_id, modulo_id), CONSTRAINT FK_7C2C33863EB29EF6 FOREIGN KEY (menu_element_id) REFERENCES menu_element (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7C2C3386C07F55F5 FOREIGN KEY (modulo_id) REFERENCES modulo (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO menu_element_modulo (menu_element_id, modulo_id) SELECT menu_element_id, modulo_id FROM __temp__menu_element_modulo');
        $this->addSql('DROP TABLE __temp__menu_element_modulo');
        $this->addSql('CREATE INDEX IDX_7C2C3386C07F55F5 ON menu_element_modulo (modulo_id)');
        $this->addSql('CREATE INDEX IDX_7C2C33863EB29EF6 ON menu_element_modulo (menu_element_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE chat');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('CREATE TEMPORARY TABLE __temp__menu_element_modulo AS SELECT menu_element_id, modulo_id FROM menu_element_modulo');
        $this->addSql('DROP TABLE menu_element_modulo');
        $this->addSql('CREATE TABLE menu_element_modulo (menu_element_id INTEGER NOT NULL, modulo_id INTEGER NOT NULL, PRIMARY KEY(menu_element_id, modulo_id), CONSTRAINT FK_7C2C33863EB29EF6 FOREIGN KEY (menu_element_id) REFERENCES menu_element (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7C2C3386C07F55F5 FOREIGN KEY (modulo_id) REFERENCES modulo (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO menu_element_modulo (menu_element_id, modulo_id) SELECT menu_element_id, modulo_id FROM __temp__menu_element_modulo');
        $this->addSql('DROP TABLE __temp__menu_element_modulo');
        $this->addSql('CREATE INDEX IDX_7C2C33863EB29EF6 ON menu_element_modulo (menu_element_id)');
        $this->addSql('CREATE INDEX IDX_7C2C3386C07F55F5 ON menu_element_modulo (modulo_id)');
        $this->addSql('CREATE INDEX IDX_A48FEF9FC07F55F5 ON menu_element_modulo (modulo_id)');
        $this->addSql('CREATE INDEX IDX_A48FEF9F3F0A914D ON menu_element_modulo (menu_element_id)');
    }
}
