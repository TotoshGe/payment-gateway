<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PanelWalletAddressStatus;
use App\Repository\PanelWalletAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One pre-provisioned deposit address in a panel's address pool for a given
 * (panel, currency, network). Reused Binance addresses can't disambiguate
 * concurrent deposit requests on their own (see ARCHITECTURE.md R1), so
 * instead of handing out the same address to two requests at once, each
 * address is held exclusively by at most one active DepositRequest and
 * released back to the pool on that request's terminal status.
 *
 * `slotIndex` identifies which underlying account/sub-account this address
 * came from (0 = the panel's own master account; >=1 = a configured
 * sub-account slot, panel-specific) -- see BinancePanel::fetchDepositAddress().
 */
#[ORM\Entity(repositoryClass: PanelWalletAddressRepository::class)]
#[ORM\Table(name: 'panel_wallet_address')]
#[ORM\UniqueConstraint(name: 'uniq_panel_currency_network_slot', columns: ['panel_id', 'currency', 'network', 'slot_index'])]
#[ORM\Index(name: 'idx_pool_lookup', columns: ['panel_id', 'currency', 'network', 'status'])]
class PanelWalletAddress
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Panel $panel;

    #[ORM\Column(length: 32)]
    private string $currency;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $network;

    #[ORM\Column]
    private int $slotIndex;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $addressTag;

    #[ORM\Column(length: 16, enumType: PanelWalletAddressStatus::class)]
    private PanelWalletAddressStatus $status;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $heldByDepositRequestId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $heldAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Panel $panel,
        string $currency,
        ?string $network,
        int $slotIndex,
        string $address,
        ?string $addressTag = null,
    ) {
        $this->id = Uuid::v7();
        $this->panel = $panel;
        $this->currency = $currency;
        $this->network = $network;
        $this->slotIndex = $slotIndex;
        $this->address = $address;
        $this->addressTag = $addressTag;
        $this->status = PanelWalletAddressStatus::FREE;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPanel(): Panel
    {
        return $this->panel;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getNetwork(): ?string
    {
        return $this->network;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getAddressTag(): ?string
    {
        return $this->addressTag;
    }

    public function getStatus(): PanelWalletAddressStatus
    {
        return $this->status;
    }

    public function getHeldByDepositRequestId(): ?Uuid
    {
        return $this->heldByDepositRequestId;
    }

    public function getHeldAt(): ?\DateTimeImmutable
    {
        return $this->heldAt;
    }

    public function markHeld(Uuid $depositRequestId): static
    {
        $this->status = PanelWalletAddressStatus::HELD;
        $this->heldByDepositRequestId = $depositRequestId;
        $this->heldAt = new \DateTimeImmutable();

        return $this;
    }

    public function release(): static
    {
        $this->status = PanelWalletAddressStatus::FREE;
        $this->heldByDepositRequestId = null;
        $this->heldAt = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s/%s #%d)', $this->address, $this->currency, $this->network ?? '-', $this->slotIndex);
    }
}
