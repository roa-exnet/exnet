<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class RemoveChatTables20250501214319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eliminar tablas del módulo Chat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chat_message');
        $this->addSql('DROP TABLE IF EXISTS chat_participant');
        $this->addSql('DROP TABLE IF EXISTS chat');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, type VARCHAR(20) NOT NULL DEFAULT "private", is_active BOOLEAN NOT NULL DEFAULT 1, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content TEXT NOT NULL, sent_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, message_type VARCHAR(50) NOT NULL DEFAULT "text", metadata TEXT DEFAULT NULL, CONSTRAINT FK_CHAT_MESSAGE_CHAT_ID FOREIGN KEY (chat_id) REFERENCES chat (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, is_active BOOLEAN NOT NULL DEFAULT 1, role VARCHAR(50) NOT NULL DEFAULT "member", CONSTRAINT FK_CHAT_PARTICIPANT_CHAT_ID FOREIGN KEY (chat_id) REFERENCES chat (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_chat_message_chat_id ON chat_message (chat_id)');
        $this->addSql('CREATE INDEX idx_chat_participant_chat_id ON chat_participant (chat_id)');
    }
}