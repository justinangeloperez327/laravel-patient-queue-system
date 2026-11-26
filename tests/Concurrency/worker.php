<?php

/**
 * Concurrency test worker. Runs one reception action in its own PHP process
 * (and therefore its own PostgreSQL connection), starting at a shared
 * instant so that several workers genuinely race.
 *
 * Usage: php tests/Concurrency/worker.php <scenario> <json-payload> <start-at-microtime>
 * Prints a single JSON line: {"ok":true,"id":...} or {"ok":false,"errors":{...}}.
 */

declare(strict_types=1);

use App\Actions\Appointments\BookAppointment;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\RegisterWalkIn;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $scenario, $json, $startAt] = $argv + [null, null, '{}', '0'];
/** @var array<string, mixed> $payload */
$payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

// Open the connection before the barrier so connection setup does not skew the race.
DB::select('select 1');

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $actor = User::query()->findOrFail($payload['actor_id']);

    $result = match ($scenario) {
        'book' => app(BookAppointment::class)->handle($actor, $payload['input']),
        'check-in' => app(CheckInAppointment::class)->handle($actor, Appointment::query()->findOrFail($payload['appointment_id'])),
        'walk-in' => app(RegisterWalkIn::class)->handle($actor, $payload['input']),
        default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]"),
    };

    echo json_encode(['ok' => true, 'id' => $result->getKey(), 'number' => $result->getAttribute('appointment_number') ?? $result->getAttribute('visit_number')]), PHP_EOL;
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'errors' => $exception->errors()]), PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'exception' => get_class($exception), 'message' => $exception->getMessage()]), PHP_EOL;
}
