<?php

declare(strict_types=1);

use Pest\Mutate\Event\Events\Test\Outcome\Tested;
use Pest\Mutate\Event\Events\Test\Outcome\TestedSubscriber;
use Pest\Mutate\Event\Events\Test\Outcome\Timeout;
use Pest\Mutate\Event\Events\Test\Outcome\TimeoutSubscriber;
use Pest\Mutate\Event\Events\Test\Outcome\Untested;
use Pest\Mutate\Event\Events\Test\Outcome\UntestedSubscriber;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationGenerationSubscriber;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\MutationTestCollection;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Repositories\MutationRepository;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Support\Container;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
    return;
}

require __DIR__.'/identity.php';
require __DIR__.'/generator.php';
requireSupportedMutationPlugin();
Container::getInstance()->add(MutationGenerator::class, new LocatedMutationGenerator);
$directory = getenv('EXPEDFLOW_MUTATION_EVIDENCE_DIR');
if ($directory === false || ! is_dir($directory)) {
    throw new RuntimeException('The mutation evidence directory must exist.');
}

final class MutationEvidence
{
    /** @var array<string, string> */
    private array $failures = [];

    private readonly string $junit;

    public function __construct(private readonly string $directory)
    {
        $this->junit = $directory.'/pest-junit.xml';
    }

    public function begin(MutationSuite $suite): void
    {
        $selection = $this->directory.'/selection.json';
        if (is_file($selection)) {
            $this->select($suite, $selection);
        }

        $this->write('generated.json', array_map(
            fn (MutationTest $test): array => mutationIdentity($test->mutation),
            iterator_to_array($this->tests($suite)),
        ));
        $this->clearJUnit();
    }

    private function select(MutationSuite $suite, string $selection): void
    {
        $plan = json_decode(file_get_contents($selection), true, flags: JSON_THROW_ON_ERROR);
        $expected = array_fill_keys($plan['inventory'], true);
        $assigned = array_fill_keys($plan['assigned'], true);
        $collections = [];

        foreach ($suite->repository->all() as $path => $collection) {
            $selected = new MutationTestCollection($collection->file);
            foreach ($collection->tests() as $test) {
                $id = mutationIdentity($test->mutation)['id'];
                if (! isset($expected[$id])) {
                    throw new RuntimeException('Pest generated an unexpected or duplicate mutation.');
                }
                unset($expected[$id]);

                if (isset($assigned[$id])) {
                    $selected->add($test);
                    unset($assigned[$id]);
                }
            }
            if ($selected->count() > 0) {
                $collections[$path] = $selected;
            }
        }

        if ($expected !== [] || $assigned !== []) {
            throw new RuntimeException('Pest did not generate the complete planned inventory.');
        }

        // Pest 5.0.2 exposes the generation event without a public repository filter.
        (new ReflectionProperty(MutationRepository::class, 'tests'))->setValue($suite->repository, $collections);
    }

    public function verify(MutationTest $test): void
    {
        /** @var Process $process */
        $process = (new ReflectionProperty(MutationTest::class, 'process'))->getValue($test);
        $exit = $process->getExitCode();
        $document = is_file($this->junit) ? simplexml_load_file($this->junit, options: LIBXML_NONET) : false;
        $cases = $document === false ? [] : $document->xpath('//testcase');
        $errors = $document === false ? [] : $document->xpath('//testcase/failure | //testcase/error');
        $output = $process->getOutput().$process->getErrorOutput();
        foreach ($errors ?: [] as $error) {
            $output .= (string) $error;
        }

        $reason = match (true) {
            $test->result() === MutationTestResult::Timeout => 'Mutant subprocess timed out.',
            preg_match('/Allowed memory size|Out of memory|Connection refused|SQLSTATE\[[^\]]+\] \[(?:1044|1045|2002|2003|2006|2013)\]|(?:outside|on) the dedicated MySQL testing connection|Could not verify the dedicated MySQL|The testing account (?:must|has)|MySQL did not authenticate/i', $output) === 1 => 'Test subprocess reported a database safety, connectivity or memory failure.',
            $test->result() === MutationTestResult::Tested && in_array($exit, [1, 2], true) && $errors => null,
            $test->result() === MutationTestResult::Untested && $exit === 0 && $cases && ! $errors => null,
            default => 'Subprocess exit and fresh PHPUnit test evidence are inconsistent.',
        };

        if ($reason !== null) {
            $this->failures[$test->getId()] = $reason;
        }

        $this->clearJUnit();
    }

    public function finish(MutationSuite $suite): void
    {
        $results = [];
        foreach ($this->tests($suite) as $test) {
            $results[] = mutationIdentity($test->mutation) + [
                'native_id' => $test->getId(),
                'result' => $test->result()->value,
                'seconds' => round($test->duration(), 6),
                'infrastructure_failure' => $this->failures[$test->getId()] ?? null,
            ];
        }

        $this->write('outcomes.json', $results);
    }

    /** @return Generator<int, MutationTest> */
    private function tests(MutationSuite $suite): Generator
    {
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                yield $test;
            }
        }
    }

    private function clearJUnit(): void
    {
        if (is_file($this->junit) && ! unlink($this->junit)) {
            throw new RuntimeException('Could not clear previous mutation subprocess evidence.');
        }
    }

    /** @param list<array<string, mixed>> $data */
    private function write(string $name, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($this->directory.'/'.$name, $json) !== strlen($json)) {
            throw new RuntimeException('Could not write mutation evidence.');
        }
    }
}

$evidence = new MutationEvidence($directory);
Facade::instance()->registerSubscribers(
    new class($evidence) implements FinishMutationGenerationSubscriber
    {
        public function __construct(private readonly MutationEvidence $evidence) {}

        public function notify(FinishMutationGeneration $event): void
        {
            $this->evidence->begin($event->mutationSuite);
        }
    },
    new class($evidence) implements TestedSubscriber
    {
        public function __construct(private readonly MutationEvidence $evidence) {}

        public function notify(Tested $event): void
        {
            $this->evidence->verify($event->test);
        }
    },
    new class($evidence) implements UntestedSubscriber
    {
        public function __construct(private readonly MutationEvidence $evidence) {}

        public function notify(Untested $event): void
        {
            $this->evidence->verify($event->test);
        }
    },
    new class($evidence) implements TimeoutSubscriber
    {
        public function __construct(private readonly MutationEvidence $evidence) {}

        public function notify(Timeout $event): void
        {
            $this->evidence->verify($event->test);
        }
    },
    new class($evidence) implements FinishMutationSuiteSubscriber
    {
        public function __construct(private readonly MutationEvidence $evidence) {}

        public function notify(FinishMutationSuite $event): void
        {
            $this->evidence->finish($event->mutationSuite);
        }
    },
);
