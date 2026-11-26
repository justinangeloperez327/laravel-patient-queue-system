<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether patients for a service pass through a triage service first.
 */
enum TriageMode: string
{
    case None = 'none';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No triage',
            self::Optional => 'Optional triage',
            self::Required => 'Triage required',
        };
    }

    public function routesToTriage(): bool
    {
        return $this !== self::None;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
