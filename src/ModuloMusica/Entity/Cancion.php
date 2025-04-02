<?php

namespace App\ModuloMusica\Entity;

use App\ModuloMusica\Repository\CancionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CancionRepository::class)]
#[ORM\Table(name: 'musica_cancion')]
class Cancion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titulo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $artista = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $album = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagen = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column]
    private ?bool $esPublico = true;

    #[ORM\Column(nullable: true)]
    private ?int $anio = null;

    #[ORM\Column(nullable: true)]
    private ?int $duracion = null;

    #[ORM\ManyToOne(inversedBy: 'canciones')]
    #[ORM\JoinColumn(name: 'genero_id', referencedColumnName: 'id')]
    private ?Genero $genero = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $creadoEn = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $actualizadoEn = null;

    public function __construct()
    {
        $this->creadoEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): static
    {
        $this->titulo = $titulo;

        return $this;
    }

    public function getArtista(): ?string
    {
        return $this->artista;
    }

    public function setArtista(?string $artista): static
    {
        $this->artista = $artista;

        return $this;
    }

    public function getAlbum(): ?string
    {
        return $this->album;
    }

    public function setAlbum(?string $album): static
    {
        $this->album = $album;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getImagen(): ?string
    {
        return $this->imagen;
    }

    public function setImagen(?string $imagen): static
    {
        $this->imagen = $imagen;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function isEsPublico(): ?bool
    {
        return $this->esPublico;
    }

    public function setEsPublico(bool $esPublico): static
    {
        $this->esPublico = $esPublico;

        return $this;
    }

    public function getAnio(): ?int
    {
        return $this->anio;
    }

    public function setAnio(?int $anio): static
    {
        $this->anio = $anio;

        return $this;
    }

    public function getDuracion(): ?int
    {
        return $this->duracion;
    }

    public function setDuracion(?int $duracion): static
    {
        $this->duracion = $duracion;

        return $this;
    }

    public function getGenero(): ?Genero
    {
        return $this->genero;
    }

    public function setGenero(?Genero $genero): static
    {
        $this->genero = $genero;

        return $this;
    }

    public function getCreadoEn(): ?\DateTimeImmutable
    {
        return $this->creadoEn;
    }

    public function setCreadoEn(\DateTimeImmutable $creadoEn): static
    {
        $this->creadoEn = $creadoEn;

        return $this;
    }

    public function getActualizadoEn(): ?\DateTimeImmutable
    {
        return $this->actualizadoEn;
    }

    public function setActualizadoEn(?\DateTimeImmutable $actualizadoEn): static
    {
        $this->actualizadoEn = $actualizadoEn;

        return $this;
    }

    public function getDuracionFormateada(): string
    {
        if (!$this->duracion) {
            return '-';
        }
        
        $minutos = floor($this->duracion / 60);
        $segundos = $this->duracion % 60;
        
        return sprintf('%d:%02d', $minutos, $segundos);
    }
}