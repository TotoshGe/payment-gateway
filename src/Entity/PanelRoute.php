<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PanelRouteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which panel serves a coin. `network` null means "any network of this
 * currency"; an exact (currency, network) route wins over it. See
 * PanelRouter for the full lookup order (falls back to the default panel).
 */
#[ORM\Entity(repositoryClass: PanelRouteRepository::class)]
#[ORM\Table(name: 'panel_route')]
#[ORM\UniqueConstraint(name: 'uniq_panel_route_currency_network', columns: ['currency', 'network'])]
#[UniqueEntity(fields: ['currency', 'network'], message: 'Маршрут для этой пары валюта/сеть уже существует.', errorPath: 'currency')]
class PanelRoute
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    #[ORM\Column(length: 32)]
    private string $currency = '';

    #[Assert\Length(max: 32)]
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $network = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Panel $panel = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $currency = '', ?string $network = null, ?Panel $panel = null, bool $enabled = true)
    {
        $this->id = Uuid::v7();
        $this->setCurrency($currency);
        $this->setNetwork($network);
        $this->panel = $panel;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper(trim($currency));
        $this->touch();

        return $this;
    }

    public function getNetwork(): ?string
    {
        return $this->network;
    }

    public function setNetwork(?string $network): static
    {
        $network = null === $network ? null : strtoupper(trim($network));
        $this->network = '' === $network ? null : $network;
        $this->touch();

        return $this;
    }

    public function getPanel(): ?Panel
    {
        return $this->panel;
    }

    public function setPanel(?Panel $panel): static
    {
        $this->panel = $panel;
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->currency.'/'.($this->network ?? '*');
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
