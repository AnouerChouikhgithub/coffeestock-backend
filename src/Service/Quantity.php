<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Scale-3 quantity validation shared by all stock-affecting write paths (INV-5, REQ-012).
 * Inputs are always strings (decimal-as-string transport); floats are never accepted.
 */
final class Quantity
{
    public const MAX = '10000000.000';

    /** Scale ≤ 3, magnitude within ±10 000 000.000. */
    public static function isValid(string $value): bool
    {
        if (1 !== preg_match('/^-?\d+(\.\d{1,3})?$/', $value)) {
            return false;
        }

        return bccomp($value, self::MAX, 3) <= 0;
    }

    /** Strictly positive + bounded (receipts, consumption, waste). */
    public static function isPositiveQuantity(string $value): bool
    {
        return self::isValid($value) && bccomp($value, '0.000', 3) > 0;
    }

    /** Non-zero + bounded (signed adjustments). */
    public static function isNonZero(string $value): bool
    {
        return self::isValid($value) && bccomp($value, '0.000', 3) !== 0;
    }

    /** Exact wire format: always scale 3 (REQ-010 / contract decimal rule). */
    public static function normalize(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = $negative ? substr($value, 1) : $value;
        if (!str_contains($value, '.')) {
            $value .= '.000';
        } else {
            $parts = explode('.', $value, 2);
            $value = $parts[0].'.'.str_pad($parts[1], 3, '0');
            if (\strlen($parts[1]) > 3) {
                $value = bcadd($value, '0', 3);
            }
        }

        return ($negative ? '-' : '').$value;
    }
}
