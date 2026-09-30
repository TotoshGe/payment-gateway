<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Repository\DepositRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DepositRequestRepository::class)]
#[ORM\Table(name: 'deposit_request')]
#[ORM\Index(name: 'idx_deposit_status', columns: ['status'])]
class DepositRequest implements PaymentRequestInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * The Okean-side UUID of the exchange record (idempotency key). Distinct
     * from `id`, which is this service's own primary key.
     */
    #[ORM\Column(length: 190, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Panel $panel;

    #[ORM\Column(length: 32)]
    private string $currency;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $network;

    /** bcmath-safe string representation, never a float. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $expectedAmount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $addressTag = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $walletAddressId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $panelDepositReference = null;

    #[ORM\Column(length: 32, enumType: PaymentRequestStatus::class)]
    private PaymentRequestStatus $status;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $receivedAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $confirmations = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** When the request last entered PAUSED; drives the paused-deposit timeout. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(length: 16, enumType: CallbackDeliveryStatus::class)]
    private CallbackDeliveryStatus $callbackStatus;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'depositRequest')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $payments;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $uuid,
        Panel $panel,
        string $currency,
        ?string $network,
        string $expectedAmount,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->uuid = $uuid;
        $this->panel = $panel;
        $this->currency = $currency;
        $this->network = $network;
        $this->expectedAmount = $expectedAmount;
        $this->expiresAt = $expiresAt;
        $this->status = PaymentRequestStatus::NEW;
        $this->callbackStatus = CallbackDeliveryStatus::PENDING;
        $this->payments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
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

    public function getExpectedAmount(): string
    {
        return $this->expectedAmount;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getAddressTag(): ?string
    {
        return $this->addressTag;
    }

    public function getWalletAddressId(): ?Uuid
    {
        return $this->walletAddressId;
    }

    public function assignWalletAddress(PanelWalletAddress $walletAddress): static
    {
        $this->walletAddressId = $walletAddress->getId();
        $this->address = $walletAddress->getAddress();
        $this->addressTag = $walletAddress->getAddressTag();
        $this->status = PaymentRequestStatus::AWAITING_PAYMENT;
        $this->touch();

        return $this;
    }

    public function getPanelDepositReference(): ?string
    {
        return $this->panelDepositReference;
    }

    public function setPanelDepositReference(?string $panelDepositReference): static
    {
        $this->panelDepositReference = $panelDepositReference;
        $this->touch();

        return $this;
    }

    public function getStatus(): PaymentRequestStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentRequestStatus $status): static
    {
        if (PaymentRequestStatus::PAUSED === $status) {
            $this->pausedAt ??= new \DateTimeImmutable();
        } else {
            $this->pausedAt = null;
        }
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getReceivedAmount(): ?string
    {
        return $this->receivedAmount;
    }

    public function setReceivedAmount(?string $receivedAmount): static
    {
        $this->receivedAmount = $receivedAmount;
        $this->touch();

        return $this;
    }

    public function getConfirmations(): ?int
    {
        return $this->confirmations;
    }

    public function setConfirmations(?int $confirmations): static
    {
        $this->confirmations = $confirmations;
        $this->touch();

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getLastPolledAt(): ?\DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function markPolled(\DateTimeImmutable $at): static
    {
        $this->lastPolledAt = $at;

        return $this;
    }

    public function getCallbackStatus(): CallbackDeliveryStatus
    {
        return $this->callbackStatus;
    }

    public function setCallbackStatus(CallbackDeliveryStatus $callbackStatus): static
    {
        $this->callbackStatus = $callbackStatus;
        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * The payment the request currently reflects: the completed one if any,
     * otherwise the most recent.
     */
    public function getLeadPayment(): ?Payment
    {
        $lead = null;
        foreach ($this->payments as $payment) {
            if (PaymentStatus::COMPLETED === $payment->getStatus()) {
                return $payment;
            }
            $lead = $payment;
        }

        return $lead;
    }

    public function addPayment(Payment $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
        }

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

    public function isFailedToSubmit(): bool
    {
        return PaymentRequestStatus::SUBMIT_FAILED === $this->status;
    }

    public function markSubmitFailed(): static
    {
        $this->status = PaymentRequestStatus::SUBMIT_FAILED;
        $this->touch();

        return $this;
    }

    public function requestType(): string
    {
        return 'deposit';
    }

    public function toCallbackPayload(): array
    {
        return [
            'request_id' => (string) $this->id,
            'uuid' => $this->uuid,
            'panel' => $this->panel->getCode(),
            'status' => $this->status->value,
            'currency' => $this->currency,
            'network' => $this->network,
            'amount' => $this->receivedAmount ?? $this->expectedAmount,
            'tx_hash' => $this->getLeadPayment()?->getTxHash(),
            'confirmations' => $this->getLeadPayment()?->getConfirmations(),
            'required_confirmations' => $this->getLeadPayment()?->getRequiredConfirmations(),
            'payment' => $this->getLeadPayment()?->toArray(),
        ];
    }

    public function callbackEventType(): string
    {
        return match ($this->status) {
            PaymentRequestStatus::COMPLETED => 'deposit.received',
            PaymentRequestStatus::EXPIRED => 'deposit.expired',
            PaymentRequestStatus::SUBMIT_FAILED, PaymentRequestStatus::FAILED => 'deposit.failed',
            PaymentRequestStatus::PAUSED => 'deposit.paused',
            default => 'deposit.updated',
        };
    }

    public function __toString(): string
    {
        return $this->uuid;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
