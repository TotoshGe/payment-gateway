<?php

declare(strict_types=1);

namespace App\Panel\TestPanel;

use App\Panel\Dto\DepositAddressResult;

/**
 * Produces format-plausible but deliberately unusable deposit details. No
 * private key, seed or real wallet is ever involved -- output is a pure
 * function of (currency, network, slotIndex) through SHA-256, so it is
 * deterministic and cannot correspond to any keypair anyone holds.
 *
 * Each family is made recognisably fake in a way native to its format:
 * checksummed formats (TRON, BTC, LTC, DOGE) are forced to FAIL their own
 * checksum, so validating wallets refuse them; formats without a checksum
 * (EVM, SOL) carry a fixed "TEST" marker instead.
 */
final class TestAddressGenerator
{
    private const EVM_NETWORKS = ['ERC20', 'ETH', 'ETHEREUM', 'BEP20', 'BSC', 'ARBITRUM', 'ARB', 'POLYGON', 'MATIC', 'OPTIMISM', 'OP', 'AVAXC', 'BASE'];
    private const TRON_NETWORKS = ['TRC20', 'TRX', 'TRON'];
    private const BTC_NETWORKS = ['BTC', 'BITCOIN'];
    private const LTC_NETWORKS = ['LTC', 'LITECOIN'];
    private const DOGE_NETWORKS = ['DOGE', 'DOGECOIN'];
    private const SOL_NETWORKS = ['SOL', 'SOLANA'];

    /** Native-chain coins that may be requested without a network code. */
    private const NATIVE_NETWORK_BY_CURRENCY = [
        'ETH' => 'ETH',
        'TRX' => 'TRX',
        'BTC' => 'BTC',
        'LTC' => 'LTC',
        'DOGE' => 'DOGE',
        'SOL' => 'SOL',
    ];

    private const FIAT_CURRENCIES = [
        'UAH', 'USD', 'EUR', 'RUB', 'PLN', 'GBP', 'KZT', 'TRY', 'CZK', 'CAD', 'AUD', 'CHF', 'GEL', 'BYN', 'MDL', 'AZN', 'UZS', 'RON', 'HUF',
    ];

    public const FIAT_TAG = 'TEST - NOT A REAL ACCOUNT';

    public function generate(string $currency, ?string $network, int $slotIndex): DepositAddressResult
    {
        $currency = strtoupper($currency);
        $network = null === $network || '' === $network ? null : strtoupper($network);

        if (null === $network) {
            if (\in_array($currency, self::FIAT_CURRENCIES, true)) {
                return $this->fiat($currency, $slotIndex);
            }
            $network = self::NATIVE_NETWORK_BY_CURRENCY[$currency] ?? null;
        }

        $seed = sprintf('binance_test|v1|%s|%s|%d', $currency, $network ?? '', $slotIndex);

        return match (true) {
            null === $network => $this->generic($currency, $network, $seed),
            \in_array($network, self::EVM_NETWORKS, true) => new DepositAddressResult('0x7e577e57'.self::derive($seed, 32, '0123456789abcdef')),
            \in_array($network, self::TRON_NETWORKS, true) => new DepositAddressResult($this->base58Check('TTEST', 29, $seed)),
            \in_array($network, self::DOGE_NETWORKS, true) => new DepositAddressResult($this->base58Check('DTEST', 29, $seed)),
            \in_array($network, self::BTC_NETWORKS, true) => new DepositAddressResult($this->bech32('bc1', $seed)),
            \in_array($network, self::LTC_NETWORKS, true) => new DepositAddressResult($this->bech32('ltc1', $seed)),
            \in_array($network, self::SOL_NETWORKS, true) => new DepositAddressResult('TEST'.self::derive($seed, 40, AddressCodec::BASE58_ALPHABET)),
            default => $this->generic($currency, $network, $seed),
        };
    }

    public static function isFiat(string $currency): bool
    {
        return \in_array(strtoupper($currency), self::FIAT_CURRENCIES, true);
    }

    private function fiat(string $currency, int $slotIndex): DepositAddressResult
    {
        $ref = strtoupper(substr(hash('sha256', sprintf('binance_test|v1|fiat|%s|%d', $currency, $slotIndex)), 0, 6));

        // 4242... is the public, never-issued gateway test PAN.
        return new DepositAddressResult(sprintf('TEST CARD 4242 4242 4242 4242 (ref T-%s)', $ref), self::FIAT_TAG);
    }

    private function generic(string $currency, ?string $network, string $seed): DepositAddressResult
    {
        return new DepositAddressResult(sprintf(
            'TEST-NOT-A-REAL-ADDRESS-%s-%s-%s',
            $currency,
            $network ?? 'NONE',
            substr(hash('sha256', $seed), 0, 8),
        ));
    }

    private function base58Check(string $prefix, int $derivedLength, string $seed): string
    {
        // Re-derive until the checksum fails; a single pass fails with
        // probability 1 - 2^-32, the loop just makes it a guarantee.
        for ($nonce = 0;; ++$nonce) {
            $candidate = $prefix.self::derive($seed.'|'.$nonce, $derivedLength, AddressCodec::BASE58_ALPHABET);
            if (!AddressCodec::isValidBase58Check($candidate)) {
                return $candidate;
            }
        }
    }

    private function bech32(string $hrpWithSeparator, string $seed): string
    {
        for ($nonce = 0;; ++$nonce) {
            $candidate = $hrpWithSeparator.'qtesttest'.self::derive($seed.'|'.$nonce, 30, AddressCodec::BECH32_CHARSET);
            if (!AddressCodec::isValidBech32($candidate)) {
                return $candidate;
            }
        }
    }

    private static function derive(string $seed, int $length, string $alphabet): string
    {
        $out = '';
        $counter = 0;
        $size = \strlen($alphabet);
        while (\strlen($out) < $length) {
            foreach (str_split(hash('sha256', $seed.'|'.$counter++, true)) as $byte) {
                $out .= $alphabet[\ord($byte) % $size];
                if (\strlen($out) === $length) {
                    break;
                }
            }
        }

        return $out;
    }
}
