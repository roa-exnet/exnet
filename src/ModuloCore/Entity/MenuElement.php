<?php

namespace App\ModuloCore\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'menu_element')]
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

    #[ORM\Column(name: 'parent_id', nullable: true)]
    private ?int $parentId = null;

    #[ORM\Column(length: 255)]
    private ?string $ruta = null;

    #[ORM\Column]
    private ?bool $enabled = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    /**
     * @var Collection<int, Modulo>
     */
    #[ORM\ManyToMany(targetEntity: Modulo::class, inversedBy: 'menuElements')]
    #[ORM\JoinTable(name: 'menu_element_modulo')]
    #[ORM\JoinColumn(name: 'menu_element_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'modulo_id', referencedColumnName: 'id')]
    private Collection $modulo;

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
        return $this->parentId;
    }

    public function setParentId(?int $parentId): static
    {
        $this->parentId = $parentId;
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
}