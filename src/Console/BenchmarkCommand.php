<?php

declare(strict_types=1);

namespace Tt0y\XLog\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Performance benchmark command for XLog.
 *
 * Run with XLOG_ENABLED=true and XLOG_ENABLED=false to compare.
 *
 * Usage:
 *   php artisan xlog:benchmark                    # 1000 records
 *   php artisan xlog:benchmark --count=5000       # 5000 records
 *   php artisan xlog:benchmark --count=10000      # 10000 records
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'xlog:benchmark 
                            {--count=1000 : Number of log records to write}
                            {--context : Include context data in logs}';

    protected $description = 'Run XLog performance benchmark';

    public function handle(): int
    {
        $count = (int)$this->option('count');
        $withContext = (bool)$this->option('context');

        $xlogEnabled = config('xlog.enabled', true);
        $channel = config('logging.default');

        $this->info('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('              XLog Performance Benchmark                        ');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('');
        $this->info("Configuration:");
        $this->info("  XLOG_ENABLED:     " . ($xlogEnabled ? 'true' : 'false'));
        $this->info("  Log channel:      {$channel}");
        $this->info("  Records to write: {$count}");
        $this->info("  With context:     " . ($withContext ? 'yes' : 'no'));
        $this->info("  PHP Version:      " . PHP_VERSION);
        $this->info("  Date:             " . date('Y-m-d H:i:s'));
        $this->info('');

        // Prepare context data
        $context = $withContext ? [
            'user_id' => 12345,
            'action' => 'benchmark.test',
            'metadata' => [
                'key1' => 'value1',
                'key2' => 'value2',
                'nested' => ['a' => 1, 'b' => 2],
            ],
        ] : [];

        // Warmup (10 records)
        $this->info('Warming up...');
        for ($i = 0; $i < 10; $i++) {
            Log::info('warmup.record', $context);
        }

        // Force garbage collection
        gc_collect_cycles();

        $this->info('Running benchmark...');
        $this->info('');

        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);

        for ($i = 0; $i < $count; $i++) {
            Log::info("benchmark.record.{$i}", $context);
        }

        $end = hrtime(true);
        $memoryAfter = memory_get_peak_usage(true);

        // Calculate results
        $totalTimeNs = $end - $start;
        $totalTimeMs = $totalTimeNs / 1_000_000;
        $totalTimeSec = $totalTimeMs / 1000;
        $avgTimeUs = ($totalTimeNs / $count) / 1_000;
        $recordsPerSecond = $totalTimeSec > 0 ? round($count / $totalTimeSec) : 0;
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Output results
        $this->info('───────────────────────────────────────────────────────────────');
        $this->info('                        RESULTS                                ');
        $this->info('───────────────────────────────────────────────────────────────');
        $this->info('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total time', number_format($totalTimeMs, 2) . ' ms'],
                ['Avg per record', number_format($avgTimeUs, 2) . ' μs'],
                ['Records/second', number_format($recordsPerSecond)],
                ['Memory used', $this->formatBytes($memoryUsed)],
                ['Peak memory', $this->formatBytes($memoryAfter)],
            ]
        );

        $this->info('');

        // JSON output for automated processing
        $result = [
            'timestamp' => date('c'),
            'xlog_enabled' => $xlogEnabled,
            'channel' => $channel,
            'count' => $count,
            'with_context' => $withContext,
            'total_time_ms' => round($totalTimeMs, 3),
            'avg_time_us' => round($avgTimeUs, 3),
            'records_per_second' => $recordsPerSecond,
            'memory_used_bytes' => $memoryUsed,
            'peak_memory_bytes' => $memoryAfter,
        ];

        $this->info('JSON (for copy/paste):');
        $this->line(json_encode($result, JSON_PRETTY_PRINT));
        $this->info('');

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
}
