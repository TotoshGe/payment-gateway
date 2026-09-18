<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestType;
use App\Repository\CallbackDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One delivery attempt trail for one logical callback event. `eventId` is
 * stable across retries of the same event (generated once, on the first
 * attempt) so Okean can dedupe deliveries; `attempt` increments per retry
 * within the same row -- we don't create a new row per HTTP try, we update
 * this one and let admins see the full attempt history via attemptLog.
 */
#[ORM\Entity(repositoryClass: CallbackDeliveryRepository::class)]
#[ORM\Table(name: 'callback_delivery')]
#[ORM\Index(name: 'idx_callback_status', columns: ['status'])]
class CallbackDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $eventId;

    #[ORM\Column(length: 16, enumType: PaymentRequestType::class)]
    private PaymentRequestType $requestType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $requestId;

    #[ORM\Column(length: 64)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column]
    private int $attempt = 0;

    #[ORM\Column(length: 16, enumType: CallbackDeliveryStatus::class)]
    private CallbackDeliveryStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastResponseCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** @var array<int, array{attempt: int, at: string, responseCode: ?int, error: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $attemptLog = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(PaymentRequestType $requestType, Uuid $requestId, string $eventType, array $payload)
    {
        $this->id = Uuid::v7();
        $this->eventId = Uuid::v7();
        $this->requestType = $requestType;
        $this->requestId = $requestId;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->status = CallbackDeliveryStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEventId(): Uuid
    {
        return $this->eventId;
    }

    public function getRequestType(): PaymentRequestType
    {
        return $this->requestType;
    }

    /** Virtual string accessor for EasyAdmin -- PHP enums can't implement __toString(). */
    public function getRequestTypeLabel(): string
    {
        return $this->requestType->value;
    }

    public function getRequestId(): Uuid
    {
        return $this->requestId;
    }

    /** Virtual string accessor for EasyAdmin, see getRequestTypeLabel(). */
    public function getRequestIdLabel(): string
    {
        return (string) $this->requestId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getStatus(): CallbackDeliveryStatus
    {
        return $this->status;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getLastResponseCode(): ?int
    {
        return $this->lastResponseCode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array<int, array{attempt: int, at: string, responseCode: ?int, error: ?string}>
     */
    public function getAttemptLog(): array
    {
        return $this->attemptLog;
    }

    public function recordSuccess(int $responseCode): static
    {
        $this->attempt++;
        $this->status = CallbackDeliveryStatus::SENT;
        $this->lastResponseCode = $responseCode;
        $this->lastError = null;
        $this->nextAttemptAt = null;
        $this->appendLog($responseCode, null);
        $this->touch();

        return $this;
    }

    public function recordFailure(?int $responseCode, string $error): static
    {
        $this->attempt++;
        $this->status = CallbackDeliveryStatus::FAILED;
        $this->lastResponseCode = $responseCode;
        $this->lastError = $error;
        $this->appendLog($responseCode, $error);
        $this->touch();

        return $this;
    }

    /** Called once Messenger's retry_strategy gives up for good, see CallbackFailureSubscriber. */
    public function markExhausted(): static
    {
        $this->status = CallbackDeliveryStatus::EXHAUSTED;
        $this->touch();

        return $this;
    }

    public function resetForManualResend(): static
    {
        $this->status = CallbackDeliveryStatus::PENDING;
        $this->nextAttemptAt = new \DateTimeImmutable();
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

    private function appendLog(?int $responseCode, ?string $error): void
    {
        $this->attemptLog[] = [
            'attempt' => $this->attempt,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'responseCode' => $responseCode,
            'error' => $error,
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
