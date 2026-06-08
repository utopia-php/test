<?php

namespace Utopia\Tests\Async;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;

use function Swoole\Coroutine\run as coroutineRun;

/**
 * Runs PHPUnit test cases concurrently, each test in its own Swoole coroutine.
 *
 * Unlike the default sequential runner, tests that wait on coroutine I/O
 * (channels, hooked sockets, `Coroutine::sleep`) overlap each other, bounded by
 * a pool of `$concurrency` coroutines. While one test awaits, another runs.
 */
final class Runner
{
    /** @var list<class-string<TestCase>> */
    private array $classes = [];

    /** @var list<Result> */
    private array $results = [];

    public function __construct(private readonly int $concurrency = 10)
    {
    }

    /**
     * @param  class-string<TestCase>  $class
     */
    public function addTestCase(string $class): self
    {
        $this->classes[] = $class;

        return $this;
    }

    /**
     * Discover every `*Test.php` under a directory and queue the TestCase classes it declares.
     */
    public function addDirectory(string $path): self
    {
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)),
            '/Test\.php$/'
        );

        foreach ($files as $file) {
            $before = \get_declared_classes();
            require_once $file->getPathname();

            foreach (\array_diff(\get_declared_classes(), $before) as $class) {
                if (\is_subclass_of($class, TestCase::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                    $this->classes[] = $class;
                }
            }
        }

        return $this;
    }

    /**
     * Run every queued test concurrently and report the outcome.
     *
     * @return int 0 when all tests passed, 1 otherwise (suitable for `exit()`)
     */
    public function run(): int
    {
        $this->bootstrap();

        // Make blocking calls (sleep, sockets, file & DB I/O, ...) yield to the
        // scheduler instead of stalling it, so tests genuinely overlap.
        \Swoole\Runtime::enableCoroutine(\SWOOLE_HOOK_ALL);

        $cases = [];
        foreach ($this->classes as $class) {
            $class::setUpBeforeClass();
            foreach ($this->cases($class) as $case) {
                $cases[] = $case;
            }
        }

        coroutineRun(function () use ($cases) {
            $pool = new Channel($this->concurrency);
            $group = new WaitGroup();

            foreach ($cases as $case) {
                $pool->push(true);
                $group->add();

                Coroutine::create(function () use ($case, $pool, $group) {
                    $this->results[] = $this->execute(...$case);
                    $pool->pop();
                    $group->done();
                });
            }

            $group->wait();
        });

        foreach (\array_unique($this->classes) as $class) {
            $class::tearDownAfterClass();
        }

        return $this->report();
    }

    /**
     * Initialise the PHPUnit configuration singleton that assertions rely on for
     * failure diffs. Normally done by PHPUnit's CLI; we are the CLI here.
     */
    private function bootstrap(): void
    {
        Registry::init((new Builder())->fromParameters([]), DefaultConfiguration::create());
    }

    /**
     * Expand a TestCase class into its individual runnable cases, one per data set.
     *
     * @param  class-string<TestCase>  $class
     * @return list<array{class-string<TestCase>, string, string, array<mixed>}> [class, method, label, args]
     */
    private function cases(string $class): array
    {
        $cases = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->getDeclaringClass()->isAbstract()) {
                continue;
            }

            if (! \str_starts_with($name, 'test') && $method->getAttributes(Test::class) === []) {
                continue;
            }

            $datasets = $this->datasets($class, $method);

            if ($datasets === null) {
                $cases[] = [$class, $name, "{$class}::{$name}", []];

                continue;
            }

            foreach ($datasets as $label => $args) {
                $cases[] = [$class, $name, "{$class}::{$name}#{$label}", $args];
            }
        }

        return $cases;
    }

    /**
     * Resolve the rows supplied by any #[DataProvider] attributes, or null when there are none.
     *
     * @param  class-string<TestCase>  $class
     * @return array<array-key, array<mixed>>|null
     */
    private function datasets(string $class, \ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(DataProvider::class);

        if ($attributes === []) {
            return null;
        }

        $rows = [];
        foreach ($attributes as $attribute) {
            $provider = $attribute->newInstance()->methodName();
            /** @var iterable<array-key, array<mixed>> $yielded */
            $yielded = $class::$provider();
            foreach ($yielded as $key => $row) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * Run a single test case in isolation and capture its outcome.
     *
     * @param  class-string<TestCase>  $class
     * @param  array<mixed>  $args
     */
    private function execute(string $class, string $method, string $label, array $args): Result
    {
        $test = new $class($method);

        $invoke = \Closure::bind(function () use ($method, $args) {
            $this->setUp();
            try {
                $this->{$method}(...$args);
            } finally {
                $this->tearDown();
            }
        }, $test, $class);

        try {
            $invoke();

            return new Result($label, Status::Passed);
        } catch (SkippedTest|IncompleteTest $e) {
            return new Result($label, Status::Skipped, $e->getMessage());
        } catch (AssertionFailedError $e) {
            return new Result($label, Status::Failed, $e->getMessage());
        } catch (\Throwable $e) {
            return new Result($label, Status::Errored, $e::class.': '.$e->getMessage());
        }
    }

    /**
     * Print results and return the process exit code.
     */
    private function report(): int
    {
        $counts = [Status::Passed->value => 0, Status::Failed->value => 0, Status::Errored->value => 0, Status::Skipped->value => 0];

        foreach ($this->results as $result) {
            echo $result->status->symbol();
            $counts[$result->status->value]++;
        }

        echo "\n\n";

        foreach ($this->results as $result) {
            if ($result->status === Status::Failed || $result->status === Status::Errored) {
                echo "{$result->status->symbol()} {$result->name}\n  {$result->message}\n";
            }
        }

        \printf(
            "\nTests: %d, Assertions: %d, Failures: %d, Errors: %d, Skipped: %d\n",
            \count($this->results),
            Assert::getCount(),
            $counts[Status::Failed->value],
            $counts[Status::Errored->value],
            $counts[Status::Skipped->value],
        );

        return $counts[Status::Failed->value] + $counts[Status::Errored->value] === 0 ? 0 : 1;
    }
}
