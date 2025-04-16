<?php

namespace App\ModuloCore\Entity;

use App\ModuloCore\Repository\ModuloRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModuloRepository::class)]
class Modulo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(length: 255)]
    private ?string $descripcion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $install_date = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $uninstall_date = null;

    /**
     * @var Collection<int, MenuElement>
     */
    #[ORM\ManyToMany(targetEntity: MenuElement::class, mappedBy: 'modulo')]
    private Collection $menuElements;

    #[ORM\Column(length: 255)]
    private ?string $icon = null;

    #[ORM\Column(length: 255)]
    private ?string $ruta = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $token = null;
    
    

    #[ORM\Column]
    private ?bool $estado = null;

    public function __construct()
    {
        $this->menuElements = new ArrayCollection();
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

    public function setDescripcion(string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getInstallDate(): ?\DateTimeImmutable
    {
        return $this->install_date;
    }

    public function setInstallDate(\DateTimeImmutable $install_date): static
    {
        $this->install_date = $install_date;

        return $this;
    }

    public function getUninstallDate(): ?\DateTimeImmutable
    {
        return $this->uninstall_date;
    }

    public function setUninstallDate(?\DateTimeImmutable $uninstall_date): static
    {
        $this->uninstall_date = $uninstall_date;

        return $this;
    }

    /**
     * @return Collection<int, MenuElement>
     */
    public function getMenuElements(): Collection
    {
        return $this->menuElements;
    }

    public function addMenuElement(MenuElement $menuElement): static
    {
        if (!$this->menuElements->contains($menuElement)) {
            $this->menuElements->add($menuElement);
            $menuElement->addModulo($this);
        }

        return $this;
    }

    public function removeMenuElement(MenuElement $menuElement): static
    {
        if ($this->menuElements->removeElement($menuElement)) {
            $menuElement->removeModulo($this);
        }

        return $this;
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

    public function getRuta(): ?string
    {
        return $this->ruta;
    }

    public function setRuta(string $ruta): static
    {
        $this->ruta = $ruta;

        return $this;
    }

    public function isEstado(): ?bool
    {
        return $this->estado;
    }

    public function setEstado(bool $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }
}
