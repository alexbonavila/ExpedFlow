<?php

declare(strict_types=1);

use Pest\Mutate\Factories\NodeTraverserFactory;
use Pest\Mutate\Mutation;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Mutate\Support\NodeVisitor;
use Pest\Mutate\Support\PhpParserFactory;
use Pest\Support\Str;
use PhpParser\Node;
use Symfony\Component\Finder\SplFileInfo;

final class LocatedMutation extends Mutation
{
    public function __construct(Mutation $mutation, public readonly int $sourceOffset)
    {
        parent::__construct(
            $mutation->file,
            hash('xxh3', $mutation->id."\0".$sourceOffset),
            $mutation->mutator,
            $mutation->startLine,
            $mutation->endLine,
            $mutation->diff,
            $mutation->modifiedSourcePath,
        );
    }

    public function __serialize(): array
    {
        return parent::__serialize() + ['source_offset' => $this->sourceOffset];
    }

    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->sourceOffset = $data['source_offset'];
    }
}

/** Resolves native ID collisions without changing the mutations Pest executes. */
final class LocatedMutationGenerator extends MutationGenerator
{
    public function generate(SplFileInfo $file, array $mutators, array $linesToMutate = [], array $classesToMutate = []): array
    {
        $native = parent::generate($file, $mutators, $linesToMutate, $classesToMutate);
        $counts = array_count_values(array_map(fn (Mutation $mutation): string => $mutation->id, $native));
        $duplicates = [];
        foreach ($native as $mutation) {
            if ($counts[$mutation->id] > 1) {
                $duplicates[$mutation->mutator][$mutation->id][] = $mutation;
            }
        }
        if ($duplicates === []) {
            return $native;
        }

        $replacements = [];
        foreach ($duplicates as $operator => $groups) {
            $replayed = $this->locate($file, $operator, $linesToMutate, $groups);
            foreach ($groups as $id => $originals) {
                $located = $replayed[$id] ?? [];
                $originalSignatures = array_map(self::signature(...), $originals);
                $locatedSignatures = array_map(self::signature(...), $located);
                sort($originalSignatures, SORT_STRING);
                sort($locatedSignatures, SORT_STRING);
                if ($originalSignatures !== $locatedSignatures) {
                    throw new RuntimeException('Could not reconcile the source sites of a native mutation collision.');
                }
                ksort($located, SORT_NUMERIC);
                $replacements[$id] = array_values($located);
            }
        }

        $resolved = [];
        foreach ($native as $mutation) {
            if ($counts[$mutation->id] === 1) {
                $resolved[] = $mutation;
            } elseif (isset($replacements[$mutation->id])) {
                array_push($resolved, ...$replacements[$mutation->id]);
                unset($replacements[$mutation->id]);
            }
        }

        if (count($resolved) !== count($native)
            || count(array_unique(array_map(fn (Mutation $mutation): string => $mutation->id, $resolved))) !== count($native)) {
            throw new RuntimeException('Native mutation collision resolution changed the inventory or left duplicate IDs.');
        }

        return $resolved;
    }

    private static function signature(Mutation $mutation): string
    {
        return hash('sha256', implode("\0", [
            $mutation->mutator, $mutation->startLine, $mutation->endLine, $mutation->modifiedSource(),
        ]));
    }

    /**
     * Reuse Pest's traversal and mutator for colliding operators only. Its native
     * callback sees the original AST node before source location is discarded.
     *
     * @param  list<int>  $lines
     * @param  array<string, list<Mutation>>  $groups
     * @return array<string, array<int, LocatedMutation>>
     */
    private function locate(SplFileInfo $file, string $operator, array $lines, array $groups): array
    {
        $contents = $file->getContents();
        $ignored = [];
        foreach (explode(PHP_EOL, $contents) as $index => $line) {
            if (str_contains($line, '@pest-mutate-ignore')) {
                $suffix = Str::after($line, '@pest-mutate-ignore:');
                $ignored[$index + 1] = $suffix === $line ? ['all'] : array_map(trim(...), explode(',', $suffix));
            }
        }

        $parser = PhpParserFactory::make();
        $offset = 0;
        $located = [];
        while (true) {
            $original = null;
            $modified = null;
            $traverser = NodeTraverserFactory::create();
            $traverser->addVisitor(new NodeVisitor(
                mutator: $operator,
                offset: $offset,
                linesToMutate: $lines,
                mutatorsToIgnoreByLine: $ignored,
                hasAlreadyMutated: static function () use (&$original): bool {
                    return $original !== null;
                },
                trackMutation: static function (int $position, Node $before, ?Node $after) use (&$offset, &$original, &$modified): void {
                    $offset = $position;
                    $original = $before;
                    $modified = $after;
                },
            ));
            $statements = $parser->parse($contents) ?? throw new RuntimeException('Could not parse mutation source.');
            $modifiedAst = $traverser->traverse($statements);
            if ($original === null) {
                return $located;
            }

            $mutation = Mutation::create($file, $operator, $original, $modified, $modifiedAst);
            if (isset($groups[$mutation->id])) {
                $site = $original->getStartFilePos();
                if ($site < 0 || isset($located[$mutation->id][$site])) {
                    throw new RuntimeException('Native mutation collision has no unique AST source sites.');
                }
                $located[$mutation->id][$site] = new LocatedMutation($mutation, $site);
            }
        }
    }
}
