<?php

namespace App\ModuloCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: "menu_element")]
class MenuElement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(type: "string", length: 50)]
    private ?string $type = null;

    #[ORM\Column(type: "integer", options: ["default" => 0])]
    private int $parentId = 0;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $ruta = null;

    #[ORM\Column(type: "boolean")]
    private bool $enabled = true;

    #[ORM\ManyToMany(targetEntity: Modulo::class, inversedBy: "menuElements")]
    #[ORM\JoinTable(name: "menu_element_modulo")]
    private Collection $modulos;

    public function __construct()
    {
        $this->modulos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getParentId(): int
    {
        return $this->parentId;
    }

    public function setParentId(int $parentId): self
    {
        $this->parentId = $parentId;
        return $this;
    }

    public function getRuta(): ?string
    {
        return $this->ruta;
    }

    public function setRuta(?string $ruta): self
    {
        $this->ruta = $ruta;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getModulos(): Collection
    {
        return $this->modulos;
    }

    public function addModulo(Modulo $modulo): self
    {
        if (!$this->modulos->contains($modulo)) {
            $this->modulos[] = $modulo;
        }
        return $this;
    }

    public function removeModulo(Modulo $modulo): self
    {
        $this->modulos->removeElement($modulo);
        return $this;
    }
}