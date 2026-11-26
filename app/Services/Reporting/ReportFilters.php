<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validated report filters. Dates are clinic-local calendar dates
 * (inclusive); instants are compared in UTC.
 */
final readonly class ReportFilters
{
    public const int MAX_DAYS = 366;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $serviceId = null,
        public ?int $providerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  from, to (Y-m-d), service_id?, provider_id?
     */
    public static function fromInput(array $input): self
    {
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $input);

        /** @var array{from: string, to: string, service_id?: int|string|null, provider_id?: int|string|null} $data */
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'provider_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ])->after(function (\Illuminate\Validation\Validator $validator) use ($input): void {
            if ($validator->errors()->isEmpty()) {
                $days = CarbonImmutable::parse((string) $input['from'])->diffInDays(CarbonImmutable::parse((string) $input['to']));

                if ($days >= self::MAX_DAYS) {
                    $validator->errors()->add('to', __('Choose a range of at most :days days.', ['days' => self::MAX_DAYS]));
                }
            }
        })->validate();

        $tz = ClinicTime::timezone();

        return new self(
            CarbonImmutable::parse($data['from'], $tz)->startOfDay(),
            CarbonImmutable::parse($data['to'], $tz)->startOfDay(),
            isset($data['service_id']) ? (int) $data['service_id'] : null,
            isset($data['provider_id']) ? (int) $data['provider_id'] : null,
        );
    }

    public static function today(): self
    {
        return new self(ClinicTime::today(), ClinicTime::today());
    }

    /** Start of the first day, UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    /** Start of the day after the last day, UTC (exclusive bound). */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->addDay()->utc();
    }

    /**
     * @return array{from: string, to: string, service_id: int|null, provider_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'service_id' => $this->serviceId,
            'provider_id' => $this->providerId,
        ];
    }
}
