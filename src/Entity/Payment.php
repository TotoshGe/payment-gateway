<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentRequestType;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One concrete money movement belonging to exactly one request: an incoming
 * on-chain transfer to a deposit request's address, or the outgoing transfer
 * of a withdrawal request. Holds its own amount/currency/network; the request
 * status is derived from its payments (PaymentRequestSynchronizer).
 *
 * `depositRequest` XOR `withdrawalRequest` is set, matching `type`.
 * (panel, type, panelReference) is unique: one on-chain transfer can be linked
 * to only one request, even if the same address is later reused by the pool.
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_panel_type_reference', columns: ['panel_id', 'type', 'panel_reference'])]
#[ORM\Index(name: 'idx_payment_status', columns: ['status'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: PaymentRequestType::class)]
    private PaymentRequestType $type;

    #[ORM\ManyToOne(targetEntity: DepositRequest::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?DepositRequest $depositRequest = null;

    #[ORM\ManyToOne(targetEntity: WithdrawalRequest::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?WithdrawalRequest $withdrawalRequest = null;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Panel $panel;

    /** bcmath-safe string representation, never a float. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $amount;

    #[ORM\Column(length: 32)]
    private string $currency;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $network;

    #[ORM\Column(length: 16, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $confirmations = null;

    /** Deposit: the panel's tx id. Withdrawal: the panel's withdrawal id. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $panelReference = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $txHash = null;

    /** Why the payment failed / was cancelled. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(PaymentRequestType $type, Panel $panel, string $amount, string $currency, ?string $network, PaymentStatus $status)
    {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->panel = $panel;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->network = $network;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function forDeposit(DepositRequest $request, string $amount, PaymentStatus $status = PaymentStatus::PENDING): self
    {
        $payment = new self(PaymentRequestType::DEPOSIT, $request->getPanel(), $amount, $request->getCurrency(), $request->getNetwork(), $status);
        $payment->depositRequest = $request;
        $request->addPayment($payment);

        return $payment;
    }

    public static function forWithdrawal(WithdrawalRequest $request): self
    {
        $payment = new self(PaymentRequestType::WITHDRAWAL, $request->getPanel(), $request->getAmount(), $request->getCurrency(), $request->getNetwork(), PaymentStatus::PENDING);
        $payment->withdrawalRequest = $request;
        $request->addPayment($payment);

        return $payment;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): PaymentRequestType
    {
        return $this->type;
    }

    public function getDepositRequest(): ?DepositRequest
    {
        return $this->depositRequest;
    }

    public function getWithdrawalRequest(): ?WithdrawalRequest
    {
        return $this->withdrawalRequest;
    }

    public function getRequest(): PaymentRequestInterface
    {
        return $this->depositRequest ?? $this->withdrawalRequest ?? throw new \LogicException('Payment is not linked to any request.');
    }

    public function getPanel(): Panel
    {
        return $this->panel;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        $this->touch();

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getNetwork(): ?string
    {
        return $this->network;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): static
    {
        $this->status = $status;
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

    public function getPanelReference(): ?string
    {
        return $this->panelReference;
    }

    public function setPanelReference(?string $panelReference): static
    {
        $this->panelReference = $panelReference;
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

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'network' => $this->network,
            'confirmations' => $this->confirmations,
            'tx_hash' => $this->txHash,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->amount, $this->currency, $this->network ?? '');
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
