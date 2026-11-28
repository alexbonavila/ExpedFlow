<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Pest\Mutate\Mutation;

const MUTATION_SCOPE = ['app', 'routes', 'bootstrap/app.php'];

/** @return array{id: string, file: string, mutator: string, start: int, end: int, mutated_sha256: string} */
function mutationIdentity(Mutation $mutation): array
{
    $path = $mutation->file->getRealPath();
    $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR;
    if ($path === false || ! str_starts_with($path, $root)) {
        throw new RuntimeException('Mutation source is outside the repository.');
    }

    $file = substr($path, strlen($root));

    $mutated = $mutation->modifiedSource();

    return [
        'id' => hash('sha256', $file."\0".$mutation->mutator."\0".$mutated),
        'file' => $file,
        'mutator' => $mutation->mutator,
        'start' => $mutation->startLine,
        'end' => $mutation->endLine,
        'mutated_sha256' => hash('sha256', $mutated),
    ];
}

function requireSupportedMutationPlugin(): void
{
    if (InstalledVersions::getReference('pestphp/pest-plugin-mutate') !== 'd0c87110416b699413cc073b8dcc92e440d81931') {
        throw new RuntimeException('Review the mutation adapter for the installed Pest mutation plugin before updating its lock.');
    }
}
