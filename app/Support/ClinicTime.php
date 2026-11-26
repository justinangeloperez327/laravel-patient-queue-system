<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Converts between UTC storage and the clinic's local wall-clock time.
 */
final class ClinicTime
{
    public static function timezone(): string
    {
        $timezone = config('clinic.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * The clinic's current local date (the operational date).
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * Parse a clinic-local date-time string (e.g. from a datetime-local input) into UTC.
     */
    public static function localToUtc(string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, self::timezone())->utc();
    }

    /**
     * Format a stored instant for a datetime-local input in clinic time.
     */
    public static function toLocalInput(\DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone())->format('Y-m-d\TH:i');
    }

    public static function toLocalDisplay(\DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone())->format('Y-m-d H:i');
    }
}
