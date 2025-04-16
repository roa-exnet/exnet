<?php

namespace App\ModuloMusica\Entity;

use App\ModuloMusica\Repository\GeneroRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GeneroRepository::class)]
#[ORM\Table(name: 'musica_genero')]
class Genero
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $icono = null;

    #[ORM\OneToMany(mappedBy: 'genero', targetEntity: Cancion::class)]
    private Collection $canciones;

    #[ORM\Column]
    private ?\DateTimeImmutable $creadoEn = null;

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

    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): static
    {
        $this->icono = $icono;

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
            $cancion->setGenero($this);
        }

        return $this;
    }

    public function removeCancion(Cancion $cancion): static
    {
        if ($this->canciones->removeElement($cancion)) {
            if ($cancion->getGenero() === $this) {
                $cancion->setGenero(null);
            }
        }

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
}