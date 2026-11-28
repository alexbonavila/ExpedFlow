<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PruneAudit extends Command
{
    protected $signature = 'expedflow:audit-prune {--dry-run : Count eligible rows without deleting}';

    protected $description = 'Prune centralized audit history older than one UTC calendar year';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now('UTC')->startOfSecond()->subYearNoOverflow();
        $dryRun = $this->option('dry-run') === true;
        $deleted = 0;
        $context = ['cutoff' => $cutoff->toISOString(), 'dry_run' => $dryRun];

        try {
            $unknown = DB::table('activity_log')->whereNull('created_at')
                ->orWhereRaw('UNIX_TIMESTAMP(created_at) = 0')->count();

            // The indexed time bound selects candidates. The epoch check handles session time zone and DST.
            $expired = DB::table('activity_log')
                ->whereRaw('created_at < FROM_UNIXTIME(?)', [$cutoff->addDay()->timestamp])
                ->whereRaw('UNIX_TIMESTAMP(created_at) BETWEEN 1 AND ?', [$cutoff->timestamp - 1]);

            if ($dryRun) {
                $eligible = $expired->count();
            } else {
                while (($ids = (clone $expired)->orderBy('created_at')->orderBy('id')->limit(500)->pluck('id'))->isNotEmpty()) {
                    $deleted += (clone $expired)->whereIn('id', $ids)->delete();
                }
                $eligible = $deleted;
            }
            $this->info("Audit rows matched: {$eligible}. Deleted: {$deleted}.");
            if ($unknown > 0) {
                $this->warn("Audit rows with unknown dates retained: {$unknown}.");
            }
            Log::info('Audit retention completed.', $context + ['matched' => $eligible, 'deleted' => $deleted, 'unknown' => $unknown]);

            return self::SUCCESS;
        } catch (Throwable) {
            Log::error('Audit retention stopped.', $context + ['deleted' => $deleted]);
            $this->error("Audit pruning stopped after deleting {$deleted} rows.");

            return self::FAILURE;
        }
    }
}
