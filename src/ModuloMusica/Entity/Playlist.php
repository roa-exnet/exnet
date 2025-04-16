<?php

namespace App\ModuloMusica\Entity;

use App\ModuloMusica\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'musica_playlist')]
class Playlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagen = null;

    #[ORM\Column(length: 255)]
    private ?string $creadorId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $creadorNombre = null;

    #[ORM\ManyToMany(targetEntity: Cancion::class)]
    #[ORM\JoinTable(name: 'musica_playlist_cancion')]
    private Collection $canciones;

    #[ORM\Column]
    private ?bool $esPublica = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $creadoEn = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $actualizadoEn = null;

    public function __construct()
    {
        $this->canciones = new ArrayCollection();
        $this->creadoEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): static
    {
        $this->nombre = $nombre;

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

    public function getCreadorId(): ?string
    {
        return $this->creadorId;
    }

    public function setCreadorId(string $creadorId): static
    {
        $this->creadorId = $creadorId;

        return $this;
    }

    public function getCreadorNombre(): ?string
    {
        return $this->creadorNombre;
    }

    public function setCreadorNombre(?string $creadorNombre): static
    {
        $this->creadorNombre = $creadorNombre;

        return $this;
    }

    /**
     * @return Collection<int, Cancion>
     */
    public function getCanciones(): Collection
    {
        return $this->canciones;
    }

    public function addCancion(Cancion $cancion): static
    {
        if (!$this->canciones->contains($cancion)) {
            $this->canciones->add($cancion);
        }

        return $this;
    }

    public function removeCancion(Cancion $cancion): static
    {
        $this->canciones->removeElement($cancion);

        return $this;
    }

    public function isEsPublica(): ?bool
    {
        return $this->esPublica;
    }

    public function setEsPublica(bool $esPublica): static
    {
        $this->esPublica = $esPublica;

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
    
    public function getDuracionTotal(): int
    {
        $duracion = 0;
        
        foreach ($this->canciones as $cancion) {
            $duracion += $cancion->getDuracion() ?? 0;
        }
        
        return $duracion;
    }
    
    public function getDuracionFormateada(): string
    {
        $duracion = $this->getDuracionTotal();
        
        if ($duracion === 0) {
            return '-';
        }
        
        $horas = floor($duracion / 3600);
        $minutos = floor(($duracion % 3600) / 60);
        $segundos = $duracion % 60;
        
        if ($horas > 0) {
            return sprintf('%d:%02d:%02d', $horas, $minutos, $segundos);
        } else {
            return sprintf('%d:%02d', $minutos, $segundos);
        }
    }
}