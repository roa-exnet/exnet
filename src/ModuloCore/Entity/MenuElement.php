<?php

namespace App\ModuloCore\Entity;

use App\ModuloCore\Repository\MenuElementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MenuElementRepository::class)]
class MenuElement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $icon = null;

    #[ORM\Column(length: 255)]
    private ?string $type = null;

    #[ORM\Column]
    private ?int $parent_id = null;

    #[ORM\Column(length: 255)]
    private ?string $ruta = null;

    /**
     * @var Collection<int, Modulo>
     */
    #[ORM\ManyToMany(targetEntity: Modulo::class, inversedBy: 'menuElements')]
    private Collection $modulo;

    #[ORM\Column]
    private ?bool $enabled = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    public function __construct()
    {
        $this->modulo = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getParentId(): ?int
    {
        return $this->parent_id;
    }

    public function setParentId(int $parent_id): static
    {
        $this->parent_id = $parent_id;

        return $this;
    }

    public function getRuta(): ?string
    {
        return $this->ruta;
    }

    public function setRuta(string $ruta): static
    {
        $this->ruta = $ruta;

        return $this;
    }

    /**
     * @return Collection<int, Modulo>
     */
    public function getModulo(): Collection
    {
        return $this->modulo;
    }

    public function addModulo(Modulo $modulo): static
    {
        if (!$this->modulo->contains($modulo)) {
            $this->modulo->add($modulo);
        }

        return $this;
    }

    public function removeModulo(Modulo $modulo): static
    {
        $this->modulo->removeElement($modulo);

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
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
}
