<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WithdrawalRequestRepository::class)]
#[ORM\Table(name: 'withdrawal_request')]
#[ORM\Index(name: 'idx_withdrawal_status', columns: ['status'])]
class WithdrawalRequest implements PaymentRequestInterface
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

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $amount;

    #[ORM\Column(length: 255)]
    private string $destinationAddress;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $destinationTag;

    /**
     * Idempotency key sent to the panel as its client-supplied order id
     * (Binance `withdrawOrderId`) so a retried executeWithdrawal() call
     * after a network failure can't result in sending funds twice.
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $clientWithdrawalId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $panelWithdrawalReference = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $txHash = null;

    #[ORM\Column(length: 16, enumType: PaymentRequestStatus::class)]
    private PaymentRequestStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(length: 16, enumType: CallbackDeliveryStatus::class)]
    private CallbackDeliveryStatus $callbackStatus;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'withdrawalRequest')]
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
        string $amount,
        string $destinationAddress,
        ?string $destinationTag,
        string $clientWithdrawalId,
    ) {
        $this->id = Uuid::v7();
        $this->uuid = $uuid;
        $this->panel = $panel;
        $this->currency = $currency;
        $this->network = $network;
        $this->amount = $amount;
        $this->destinationAddress = $destinationAddress;
        $this->destinationTag = $destinationTag;
        $this->clientWithdrawalId = $clientWithdrawalId;
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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getDestinationAddress(): string
    {
        return $this->destinationAddress;
    }

    public function getDestinationTag(): ?string
    {
        return $this->destinationTag;
    }

    public function getClientWithdrawalId(): string
    {
        return $this->clientWithdrawalId;
    }

    public function getPanelWithdrawalReference(): ?string
    {
        return $this->panelWithdrawalReference;
    }

    public function setPanelWithdrawalReference(?string $panelWithdrawalReference): static
    {
        $this->panelWithdrawalReference = $panelWithdrawalReference;
        $this->touch();

        return $this;
    }

    public function getTxHash(): ?string
    {
        return $this->txHash;
    }

    public function setTxHash(?string $txHash): static
    {
        $this->txHash = $txHash;
        $this->touch();

        return $this;
    }

    public function getStatus(): PaymentRequestStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentRequestStatus $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): static
    {
        $this->failureReason = $failureReason;
        $this->touch();

        return $this;
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

    public function getLeadPayment(): ?Payment
    {
        $last = $this->payments->last();

        return false === $last ? null : $last;
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

    public function requestType(): string
    {
        return 'withdrawal';
    }

    public function toCallbackPayload(): array
    {
        return [
            'request_id' => (string) $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'network' => $this->network,
            'amount' => $this->amount,
            'tx_hash' => $this->txHash,
            'payment' => $this->getLeadPayment()?->toArray(),
        ];
    }

    public function callbackEventType(): string
    {
        return match ($this->status) {
            PaymentRequestStatus::COMPLETED => 'withdrawal.completed',
            PaymentRequestStatus::FAILED, PaymentRequestStatus::SUBMIT_FAILED => 'withdrawal.failed',
            PaymentRequestStatus::PAUSED => 'withdrawal.paused',
            default => 'withdrawal.updated',
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
