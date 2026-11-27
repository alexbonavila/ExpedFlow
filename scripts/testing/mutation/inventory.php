<?php

declare(strict_types=1);

use Pest\Mutate\Cache\NullStore;
use Pest\Mutate\Mutators\Sets\DefaultSet;
use Pest\Mutate\Support\FileFinder;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Support\Container;
use Psr\SimpleCache\CacheInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/identity.php';

requireSupportedMutationPlugin();
Container::getInstance()->add(CacheInterface::class, new NullStore);
$generator = new MutationGenerator;
$mutators = DefaultSet::mutators();
$inventory = [];

foreach (FileFinder::files(MUTATION_SCOPE, []) as $file) {
    foreach ($generator->generate($file, $mutators) as $mutation) {
        $inventory[] = mutationIdentity($mutation);
    }
}

echo json_encode($inventory, JSON_THROW_ON_ERROR), PHP_EOL;
