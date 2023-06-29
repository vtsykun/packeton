<?php

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packeton\Repository\SubEntityRepository;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: SubEntityRepository::class)]
#[ORM\Table('sub_repository')]
#[ORM\UniqueConstraint(columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class SubRepository
{
    public const ROOT_REPO = 0;
    public const AUTO_HOST = -1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $urls = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $packages = null;

    /** @internal  */
    private ?array $cachedIds = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getUrls(): ?string
    {
        return $this->urls;
    }

    public function setUrls(?string $urls): static
    {
        $this->urls = $urls;

        return $this;
    }

    public function addPackage(string $name): static
    {
        $this->packages ??= [];
        $this->packages[] = $name;
        $this->packages = array_values(array_unique($this->packages));

        return $this;
    }

    public function removePackage(string $name): static
    {
        if ($this->packages && false !== ($index = array_search($name, $this->packages))) {
            unset($this->packages[$index]);
            $this->packages = array_values(array_unique($this->packages));
        }

        return $this;
    }

    public function getPackages(): ?array
    {
        return $this->packages;
    }

    public function setPackages(?array $packages): static
    {
        $this->packages = $packages;

        return $this;
    }

    public function filterAllowed(array $packageNames): array
    {
        return array_intersect($this->packages ?? [], $packageNames);
    }

    /**
     * @return array|null
     */
    public function getCachedIds(): ?array
    {
        return $this->cachedIds;
    }

    /**
     * @param array|null $cachedIds
     * @return $this
     */
    public function setCachedIds(?array $cachedIds): static
    {
        $this->cachedIds = $cachedIds;
        return $this;
    }
}
