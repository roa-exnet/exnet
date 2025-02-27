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

    #[ORM\Column]
    private ?\DateTimeImmutable $install_date = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $uninstall_date = null;

    /**
     * @var Collection<int, MenuElement>
     */
    #[ORM\ManyToMany(targetEntity: MenuElement::class, mappedBy: 'modulo')]
    private Collection $menuElements;

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
}
