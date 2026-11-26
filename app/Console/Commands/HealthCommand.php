<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Health probe for monitoring (cron or a supervisor): database, Redis,
 * queue backlog, failed jobs and the WebSocket server. Prints JSON and
 * exits non-zero when a critical component is down. Contains no patient data.
 */
final class HealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'clinic:health {--max-backlog=500 : Queue size above which the check fails}';

    /**
     * @var string
     */
    protected $description = 'Report the health of the database, Redis, queues and WebSocket server as JSON';

    public function handle(): int
    {
        $checks = [];

        $checks['database'] = $this->probe(fn (): string => DB::selectOne('select 1 as ok') !== null ? 'up' : 'down');
        $checks['redis'] = $this->probe(fn (): string => Redis::connection()->ping() !== false ? 'up' : 'down');
        $checks['queue_backlog'] = $this->probe(fn (): int => Queue::size());
        $checks['failed_jobs'] = $this->probe(fn (): int => (int) DB::table('failed_jobs')->count());
        $checks['websocket'] = $this->probe(function (): string {
            $host = (string) config('broadcasting.connections.reverb.options.host', 'localhost');
            $port = (int) config('broadcasting.connections.reverb.options.port', 8080);
            $socket = @fsockopen($host, $port, $errno, $errstr, 2.0);

            if ($socket === false) {
                return 'down';
            }

            fclose($socket);

            return 'up';
        });

        $backlog = $checks['queue_backlog'];
        $critical = $checks['database'] !== 'up'
            || $checks['redis'] !== 'up'
            || (is_int($backlog) && $backlog > (int) $this->option('max-backlog'));

        $this->line((string) json_encode([
            'ok' => ! $critical,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], JSON_PRETTY_PRINT));

        return $critical ? self::FAILURE : self::SUCCESS;
    }

    private function probe(callable $probe): string|int
    {
        try {
            $result = $probe();

            return is_int($result) || is_string($result) ? $result : 'unknown';
        } catch (Throwable) {
            return 'down';
        }
    }
}
