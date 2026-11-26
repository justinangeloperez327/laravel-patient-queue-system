<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sex recorded for demographics. Optional on the patient record.
 */
enum Sex: string
{
    case Female = 'female';
    case Male = 'male';
    case Intersex = 'intersex';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Intersex => 'Intersex',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $sex): string => $sex->value, self::cases());
    }
}
