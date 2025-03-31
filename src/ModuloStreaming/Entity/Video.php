<?php

namespace App\ModuloStreaming\Entity;

use App\ModuloStreaming\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titulo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagen = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column]
    private ?bool $esPublico = true;

    #[ORM\Column(length: 20)]
    private ?string $tipo = null; // 'pelicula' o 'serie'

    #[ORM\Column(nullable: true)]
    private ?int $anio = null;

    #[ORM\ManyToOne(inversedBy: 'videos')]
    private ?Categoria $categoria = null;

    #[ORM\Column(nullable: true)]
    private ?int $temporada = null;

    #[ORM\Column(nullable: true)]
    private ?int $episodio = null;

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

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        $this->tipo = $tipo;

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

    public function getCategoria(): ?Categoria
    {
        return $this->categoria;
    }

    public function setCategoria(?Categoria $categoria): static
    {
        $this->categoria = $categoria;

        return $this;
    }

    public function getTemporada(): ?int
    {
        return $this->temporada;
    }

    public function setTemporada(?int $temporada): static
    {
        $this->temporada = $temporada;

        return $this;
    }

    public function getEpisodio(): ?int
    {
        return $this->episodio;
    }

    public function setEpisodio(?int $episodio): static
    {
        $this->episodio = $episodio;

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
}