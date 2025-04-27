<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class RemoveStreamingTables20250427195824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eliminar tablas del módulo Streaming';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS video');
        $this->addSql('DROP TABLE IF EXISTS categoria');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categoria (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL)');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, categoria_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL DEFAULT 1, tipo VARCHAR(20) NOT NULL, anio INTEGER DEFAULT NULL, temporada INTEGER DEFAULT NULL, episodio INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL, actualizado_en DATETIME DEFAULT NULL, CONSTRAINT FK_VIDEO_CATEGORIA FOREIGN KEY (categoria_id) REFERENCES categoria (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_VIDEO_CATEGORIA ON video (categoria_id)');
    }
}