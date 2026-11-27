<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Pest\Mutate\Mutation;

const MUTATION_SCOPE = ['app', 'routes', 'bootstrap/app.php'];

/** @return array{id: string, file: string, mutator: string, start: int, end: int} */
function mutationIdentity(Mutation $mutation): array
{
    $path = $mutation->file->getRealPath();
    $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR;
    if ($path === false || ! str_starts_with($path, $root)) {
        throw new RuntimeException('Mutation source is outside the repository.');
    }

    $file = substr($path, strlen($root));

    return [
        'id' => hash('sha256', $file."\0".$mutation->mutator."\0".$mutation->modifiedSource()),
        'file' => $file,
        'mutator' => $mutation->mutator,
        'start' => $mutation->startLine,
        'end' => $mutation->endLine,
    ];
}

function requireSupportedMutationPlugin(): void
{
    if (InstalledVersions::getReference('pestphp/pest-plugin-mutate') !== 'd0c87110416b699413cc073b8dcc92e440d81931') {
        throw new RuntimeException('Review the mutation adapter for the installed Pest mutation plugin before updating its lock.');
    }
}
