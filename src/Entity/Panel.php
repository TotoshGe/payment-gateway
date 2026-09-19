<?php

declare(strict_types=1);

namespace App\Entity;

use App\Panel\BinanceTest\BinanceTestPanel;
use App\Repository\PanelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Configuration for one provider integration (Binance is the first). `code`
 * is also the key PanelRegistry uses to resolve the PanelInterface
 * implementation, so it must match the driver's getCode().
 */
#[ORM\Entity(repositoryClass: PanelRepository::class)]
#[ORM\Table(name: 'panel')]
class Panel
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    /**
     * Ciphertext only (base64 of a libsodium secretbox), never plaintext.
     * Decrypted on demand by PanelCredentialsEncryptor, never exposed via
     * API/EasyAdmin. Nullable so a panel row can exist before credentials
     * are configured.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedCredentials = null;

    /**
     * Not persisted -- write-only transient carrier for the EasyAdmin form.
     * When non-empty at flush time, PanelCrudController re-encrypts it into
     * encryptedCredentials and this is discarded. Never populated from DB.
     */
    private ?string $plainCredentialsInput = null;

    /**
     * Panel-specific non-secret config: e.g. Binance sub-account identifiers
     * used to provision extra deposit-address pool slots (see
     * PanelWalletAddress), base URLs, timeouts.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    /**
     * Reference list of {currency, network} pairs this panel is expected to
     * serve -- informational/admin-facing, not enforced by the panel driver
     * itself.
     *
     * @var array<int, array{currency: string, network: ?string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $supportedCurrencies = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $label)
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getEncryptedCredentials(): ?string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(?string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;

        return $this;
    }

    public function getPlainCredentialsInput(): ?string
    {
        return $this->plainCredentialsInput;
    }

    public function setPlainCredentialsInput(?string $plainCredentialsInput): static
    {
        $this->plainCredentialsInput = $plainCredentialsInput;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /** Virtual accessor for EasyAdmin -- there's no native JSON-object field type. */
    public function getConfigJson(): string
    {
        return json_encode($this->config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    public function setConfigJson(?string $configJson): static
    {
        if (null === $configJson || '' === trim($configJson)) {
            $this->config = [];

            return $this;
        }

        $decoded = json_decode($configJson, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Config must be a valid JSON object.');
        }

        $this->config = $decoded;

        return $this;
    }

    /**
     * @return array<int, array{currency: string, network: ?string}>
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * @param array<int, array{currency: string, network: ?string}> $supportedCurrencies
     */
    public function setSupportedCurrencies(array $supportedCurrencies): static
    {
        $this->supportedCurrencies = $supportedCurrencies;

        return $this;
    }

    public function getSupportedCurrenciesJson(): string
    {
        return json_encode($this->supportedCurrencies, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    public function setSupportedCurrenciesJson(?string $json): static
    {
        if (null === $json || '' === trim($json)) {
            $this->supportedCurrencies = [];

            return $this;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Supported currencies must be a valid JSON array.');
        }

        $this->supportedCurrencies = $decoded;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isTestPanel(): bool
    {
        return BinanceTestPanel::CODE === $this->code;
    }

    public function __toString(): string
    {
        return $this->isTestPanel() ? $this->label.' [TEST]' : $this->label;
    }
}
