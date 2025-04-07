<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250407170214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
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
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE musica_cancion');
        $this->addSql('DROP TABLE musica_genero');
        $this->addSql('DROP TABLE musica_playlist');
        $this->addSql('DROP TABLE musica_playlist_cancion');
    }
}
