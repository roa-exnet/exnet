<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class RemoveMusicaTables20250403174131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eliminar tablas del módulo Música';
    }

    public function up(Schema $schema): void
    {
        // Eliminar tablas en orden seguro (primero las que tienen claves foráneas)
        $this->addSql('DROP TABLE IF EXISTS musica_playlist_cancion');
        $this->addSql('DROP TABLE IF EXISTS musica_playlist');
        $this->addSql('DROP TABLE IF EXISTS musica_cancion');
        $this->addSql('DROP TABLE IF EXISTS musica_genero');
    }

    public function down(Schema $schema): void
    {
        // Este método puede quedarse vacío o implementar la recreación de tablas si es necesario
        $this->addSql('CREATE TABLE musica_genero (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable))');
        $this->addSql('CREATE TABLE musica_cancion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, genero_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, artista VARCHAR(255) DEFAULT NULL, album VARCHAR(255) DEFAULT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, anio INTEGER DEFAULT NULL, duracion INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable), actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable), CONSTRAINT FK_GENERO_ID FOREIGN KEY (genero_id) REFERENCES musica_genero (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE TABLE musica_playlist (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, creador_id VARCHAR(255) NOT NULL, creador_nombre VARCHAR(255) DEFAULT NULL, es_publica BOOLEAN NOT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable), actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable))');
        $this->addSql('CREATE TABLE musica_playlist_cancion (playlist_id INTEGER NOT NULL, cancion_id INTEGER NOT NULL, PRIMARY KEY(playlist_id, cancion_id), CONSTRAINT FK_PLAYLIST_ID FOREIGN KEY (playlist_id) REFERENCES musica_playlist (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CANCION_ID FOREIGN KEY (cancion_id) REFERENCES musica_cancion (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }
}