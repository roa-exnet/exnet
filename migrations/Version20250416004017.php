<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250416004017 extends AbstractMigration
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
        $this->addSql('CREATE TABLE musica_cancion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, genero_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, artista VARCHAR(255) DEFAULT NULL, album VARCHAR(255) DEFAULT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, anio INTEGER DEFAULT NULL, duracion INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_B1CAA9BBBCE7B795 FOREIGN KEY (genero_id) REFERENCES musica_genero (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B1CAA9BBBCE7B795 ON musica_cancion (genero_id)');
        $this->addSql('CREATE TABLE musica_genero (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('CREATE TABLE musica_playlist (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, creador_id VARCHAR(255) NOT NULL, creador_nombre VARCHAR(255) DEFAULT NULL, es_publica BOOLEAN NOT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('CREATE TABLE musica_playlist_cancion (playlist_id INTEGER NOT NULL, cancion_id INTEGER NOT NULL, PRIMARY KEY(playlist_id, cancion_id), CONSTRAINT FK_31ED2E506BBD148 FOREIGN KEY (playlist_id) REFERENCES musica_playlist (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_31ED2E509B1D840F FOREIGN KEY (cancion_id) REFERENCES musica_cancion (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_31ED2E506BBD148 ON musica_playlist_cancion (playlist_id)');
        $this->addSql('CREATE INDEX IDX_31ED2E509B1D840F ON musica_playlist_cancion (cancion_id)');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, categoria_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, tipo VARCHAR(20) NOT NULL, anio INTEGER DEFAULT NULL, temporada INTEGER DEFAULT NULL, episodio INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_7CC7DA2C3397707A FOREIGN KEY (categoria_id) REFERENCES categoria (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C3397707A ON video (categoria_id)');
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
        , CONSTRAINT FK_FAB3FC161A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_message (id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata) SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM __temp__chat_message');
        $this->addSql('DROP TABLE __temp__chat_message');
        $this->addSql('CREATE INDEX IDX_FAB3FC161A9A7125 ON chat_message (chat_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_participant AS SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM chat_participant');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , left_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, role VARCHAR(50) NOT NULL, CONSTRAINT FK_E8ED9C891A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_participant (id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role) SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM __temp__chat_participant');
        $this->addSql('DROP TABLE __temp__chat_participant');
        $this->addSql('CREATE INDEX IDX_E8ED9C891A9A7125 ON chat_participant (chat_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE categoria');
        $this->addSql('DROP TABLE musica_cancion');
        $this->addSql('DROP TABLE musica_genero');
        $this->addSql('DROP TABLE musica_playlist');
        $this->addSql('DROP TABLE musica_playlist_cancion');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat AS SELECT id, name, created_at, closed_at, type, is_active FROM chat');
        $this->addSql('DROP TABLE chat');
        $this->addSql('CREATE TABLE chat (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, type VARCHAR(20) DEFAULT \'private\' NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO chat (id, name, created_at, closed_at, type, is_active) SELECT id, name, created_at, closed_at, type, is_active FROM __temp__chat');
        $this->addSql('DROP TABLE __temp__chat');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_message AS SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM chat_message');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT DEFAULT NULL, chat_id VARCHAR(255) NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content CLOB NOT NULL, sent_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, message_type VARCHAR(50) DEFAULT \'text\' NOT NULL, metadata CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO chat_message (id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata) SELECT id, chat_id, sender_identifier, sender_name, content, sent_at, read_at, message_type, metadata FROM __temp__chat_message');
        $this->addSql('DROP TABLE __temp__chat_message');
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_participant AS SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM chat_participant');
        $this->addSql('DROP TABLE chat_participant');
        $this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT DEFAULT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, role VARCHAR(50) DEFAULT \'member\' NOT NULL)');
        $this->addSql('INSERT INTO chat_participant (id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role) SELECT id, chat_id, participant_identifier, participant_name, joined_at, left_at, is_active, role FROM __temp__chat_participant');
        $this->addSql('DROP TABLE __temp__chat_participant');
    }
}
