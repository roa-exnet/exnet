<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250416005919 extends AbstractMigration
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
        $this->addSql('DROP TABLE musica_cancion');
        $this->addSql('DROP TABLE musica_genero');
        $this->addSql('DROP TABLE musica_playlist');
        $this->addSql('DROP TABLE musica_playlist_cancion');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE musica_cancion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, genero_id INTEGER DEFAULT NULL, titulo CLOB NOT NULL COLLATE "BINARY", artista CLOB DEFAULT NULL COLLATE "BINARY", album CLOB DEFAULT NULL COLLATE "BINARY", descripcion CLOB DEFAULT NULL COLLATE "BINARY", imagen CLOB DEFAULT NULL COLLATE "BINARY", url CLOB DEFAULT NULL COLLATE "BINARY", es_publico INTEGER NOT NULL, anio INTEGER DEFAULT NULL, duracion INTEGER DEFAULT NULL, creado_en CLOB NOT NULL COLLATE "BINARY", actualizado_en CLOB DEFAULT NULL COLLATE "BINARY", FOREIGN KEY (genero_id) REFERENCES musica_genero (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B1CAA9BBBCE7B795 ON musica_cancion (genero_id)');
        $this->addSql('CREATE TABLE musica_genero (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre CLOB NOT NULL COLLATE "BINARY", descripcion CLOB DEFAULT NULL COLLATE "BINARY", icono CLOB DEFAULT NULL COLLATE "BINARY", creado_en CLOB NOT NULL COLLATE "BINARY")');
        $this->addSql('CREATE TABLE musica_playlist (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre CLOB NOT NULL COLLATE "BINARY", descripcion CLOB DEFAULT NULL COLLATE "BINARY", imagen CLOB DEFAULT NULL COLLATE "BINARY", creador_id CLOB NOT NULL COLLATE "BINARY", creador_nombre CLOB DEFAULT NULL COLLATE "BINARY", es_publica INTEGER NOT NULL, creado_en CLOB NOT NULL COLLATE "BINARY", actualizado_en CLOB DEFAULT NULL COLLATE "BINARY")');
        $this->addSql('CREATE TABLE musica_playlist_cancion (playlist_id INTEGER NOT NULL, cancion_id INTEGER NOT NULL, PRIMARY KEY(playlist_id, cancion_id), FOREIGN KEY (playlist_id) REFERENCES musica_playlist (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (cancion_id) REFERENCES musica_cancion (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_31ED2E506BBD148 ON musica_playlist_cancion (playlist_id)');
        $this->addSql('CREATE INDEX IDX_31ED2E509B1D840F ON musica_playlist_cancion (cancion_id)');
        $this->addSql('DROP TABLE chat');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE chat_participant');
    }
}
