<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Helpers for tests that need committed data visible to other connections.
 * These tests do not use RefreshDatabase transactions; instead every table
 * is truncated before and after each test.
 */
final class Harness
{
    public static function resetDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'"))
            ->map(fn (object $row): string => '"'.$row->tablename.'"')
            ->implode(', ');

        // audit_logs is append-only for UPDATE/DELETE; TRUNCATE is permitted for test resets only.
        DB::statement("TRUNCATE TABLE {$tables} RESTART IDENTITY CASCADE");

        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    }

    /**
     * Run one worker process per payload, all starting at the same instant.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    public static function race(string $scenario, array $payloads): array
    {
        $startAt = microtime(true) + 2.0;
        $processes = [];

        foreach ($payloads as $index => $payload) {
            $command = [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $scenario, json_encode($payload, JSON_THROW_ON_ERROR), sprintf('%.6F', $startAt)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start worker process.');
            }

            $processes[$index] = [$process, $pipes];
        }

        $results = [];

        foreach ($processes as $index => [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $line = trim((string) strrchr("\n".trim($stdout), "\n"));
            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Worker {$index} produced no result. stdout: {$stdout} stderr: {$stderr}");
            }

            $results[] = $decoded;
        }

        return $results;
    }
}
