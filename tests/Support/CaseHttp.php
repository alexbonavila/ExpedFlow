<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\ExpedientType;
use App\Models\Status;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait CaseHttp
{
    use AuthorizationHttp;

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    protected function caseInput(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Synthetic case',
            'description' => 'Plain case description',
            'expedient_type_id' => $overrides['expedient_type_id'] ?? ExpedientType::factory()->create()->id,
            'requester_client_id' => $overrides['requester_client_id'] ?? Client::factory()->create()->id,
            'status_id' => $overrides['status_id'] ?? Status::factory()->create()->id,
        ];
    }

    /** @return array{cookies: array<string, string>, csrf: string} */
    protected function caseBrowserCredentials(): array
    {
        return ['cookies' => $this->browserCookies, 'csrf' => $this->browserCookies['XSRF-TOKEN']];
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    protected function commitAgainstCaseContender(array $operation, ?Closure $beforeCommit = null): array
    {
        $process = new Process(['php', 'tests/Fixtures/CaseContender.php'], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(15)->setInput(json_encode($operation, JSON_THROW_ON_ERROR));
        $process->start();
        try {
            expect($process->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "ready\n")))
                ->toBeTrue('The contender must reach the expected MySQL statement.');
            $beforeCommit?->__invoke();
            DB::commit();

            expect($process->wait())->toBe(0, $process->getErrorOutput());
            expect(preg_match('/^result:(.+)$/m', $process->getOutput(), $matches))->toBe(1, $process->getOutput());

            return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $process->stop();
        }
    }
}
