<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Contract timestamps are UTC ISO-8601 and must not be in the future (INV-7).
 * Shared by all phase-5 write paths.
 */
final class Timestamp
{
    /**
     * @return \DateTimeImmutable|array{field: string, issue: string}
     */
    public static function parseUtc(?string $value, string $field): \DateTimeImmutable|array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (null === $value || '' === $value) {
            return $now;
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value, new \DateTimeZone('UTC'));
        if (false === $parsed) {
            return ['field' => $field, 'issue' => 'must be an ISO-8601 UTC timestamp'];
        }
        if ($parsed > $now) {
            return ['field' => $field, 'issue' => 'must not be in the future (INV-7)'];
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }
}
