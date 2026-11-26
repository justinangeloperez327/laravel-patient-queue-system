<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Readiness\PreflightChecks;
use Illuminate\Console\Command;

final class PreflightCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'clinic:preflight {--json : Output machine-readable JSON}';

    /**
     * @var string
     */
    protected $description = 'Check production readiness (configuration, accounts, database safeguards); fails on any blocking issue';

    public function handle(PreflightChecks $checks): int
    {
        $results = $checks->run();
        $failed = array_filter($results, fn (array $result): bool => $result['status'] === 'fail');

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $failed === [], 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Status', 'Check', 'Detail'], array_map(fn (array $r): array => [strtoupper($r['status']), $r['check'], $r['detail']], $results));
            $failed === []
                ? $this->components->info('No blocking issues. Complete the MANUAL items before handling real patient data.')
                : $this->components->error(count($failed).' blocking issue(s).');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
