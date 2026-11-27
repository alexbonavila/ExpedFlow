<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\ExpedientType;
use App\Models\Status;

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
}
