<?php

declare(strict_types=1);

namespace App\Panel\BinanceTest;

/**
 * Checksum validators used only to *prove* that generated test addresses are
 * NOT valid: TestAddressGenerator keeps re-deriving until the checksum fails,
 * so wallets/exchanges that validate checksums (TRON, BTC, LTC, DOGE) reject
 * the address outright instead of accepting a transfer to it.
 */
final class AddressCodec
{
    public const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    public const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    public static function isValidBase58Check(string $address): bool
    {
        $decoded = self::base58Decode($address);
        if (null === $decoded || \strlen($decoded) < 5) {
            return false;
        }

        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);

        return hash_equals(substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4), $checksum);
    }

    public static function isValidBech32(string $address): bool
    {
        $address = strtolower($address);
        $separator = strrpos($address, '1');
        if (false === $separator || $separator < 1 || \strlen($address) - $separator - 1 < 6) {
            return false;
        }

        $hrp = substr($address, 0, $separator);
        $values = [];
        foreach (str_split(substr($address, $separator + 1)) as $char) {
            $position = strpos(self::BECH32_CHARSET, $char);
            if (false === $position) {
                return false;
            }
            $values[] = $position;
        }

        $polymod = self::bech32Polymod(array_merge(self::bech32HrpExpand($hrp), $values));

        return 1 === $polymod || 0x2bc830a3 === $polymod;
    }

    private static function base58Decode(string $value): ?string
    {
        $bytes = [];
        foreach (str_split($value) as $char) {
            $carry = strpos(self::BASE58_ALPHABET, $char);
            if (false === $carry) {
                return null;
            }
            foreach ($bytes as $i => $byte) {
                $carry += $byte * 58;
                $bytes[$i] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }

        $leadingZeros = \strlen($value) - \strlen(ltrim($value, '1'));

        return str_repeat("\0", $leadingZeros).implode('', array_map('chr', array_reverse($bytes)));
    }

    /**
     * @param list<int> $values
     */
    private static function bech32Polymod(array $values): int
    {
        $generators = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $checksum = 1;
        foreach ($values as $value) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; ++$i) {
                if (($top >> $i) & 1) {
                    $checksum ^= $generators[$i];
                }
            }
        }

        return $checksum;
    }

    /**
     * @return list<int>
     */
    private static function bech32HrpExpand(string $hrp): array
    {
        $expanded = [];
        foreach (str_split($hrp) as $char) {
            $expanded[] = \ord($char) >> 5;
        }
        $expanded[] = 0;
        foreach (str_split($hrp) as $char) {
            $expanded[] = \ord($char) & 31;
        }

        return $expanded;
    }
}
