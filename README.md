# Utopia Tests

A lightweight PHP testing library that provides useful testing utilities and extensions for PHPUnit.

## Installation

```bash
composer require utopia-php/tests
```

## Requirements

- PHP 8.3 or later
- PHPUnit 12.4 or later

## Features

### Async Extension

The `Async` trait provides utilities for testing asynchronous or eventually consistent behavior.

#### `assertEventually()`

Repeatedly executes a callable until it succeeds or times out. This is useful for testing:
- Asynchronous operations
- Eventually consistent systems
- Polling-based workflows
- Background jobs

**Usage:**

```php
use PHPUnit\Framework\TestCase;
use Utopia\Tests\Extensions\Async;

class MyTest extends TestCase
{
    use Async;

    public function testAsyncOperation(): void
    {
        $result = null;

        // Start some async operation
        $this->startAsyncJob(function ($data) use (&$result) {
            $result = $data;
        });

        // Wait until the result is set (max 10 seconds, check every 500ms)
        self::assertEventually(function () use (&$result) {
            $this->assertNotNull($result);
            $this->assertSame('expected', $result);
        }, timeoutMs: 10000, waitMs: 500);
    }
}
```

**Parameters:**

- `callable $probe` - The function to execute repeatedly. Should contain assertions.
- `int $timeoutMs` - Maximum time to wait in milliseconds (default: 10000)
- `int $waitMs` - Time to wait between attempts in milliseconds (default: 500)

**Critical Exceptions:**

If you need to immediately fail the test without retrying, throw a `Critical` exception:

```php
use Utopia\Tests\Extensions\Async\Exceptions\Critical;

self::assertEventually(function () use ($connection) {
    if ($connection->isClosed()) {
        throw new Critical('Connection closed unexpectedly');
    }
    $this->assertTrue($connection->hasData());
});
```

### Coroutine Runner

`Utopia\Tests\Async\Runner` runs your existing PHPUnit test cases concurrently —
each test in its own [Swoole](https://www.swoole.com/) coroutine, bounded by a
pool of N coroutines. While one test waits on coroutine I/O (a channel, a hooked
socket, `Coroutine::sleep`), another runs, so a suite of slow integration tests
finishes in roughly the time of its slowest test rather than their sum.

It requires the `swoole` extension.

**Command line:**

```bash
# Run every *Test.php under tests/, 10 at a time (the default)
vendor/bin/co-phpunit tests --concurrency=20
```

**Programmatically:**

```php
use Utopia\Tests\Async\Runner;

$runner = new Runner(concurrency: 20);
$runner->addDirectory(__DIR__ . '/tests');
// ...or queue classes explicitly: $runner->addTestCase(MyTest::class);

exit($runner->run());
```

Your test classes are plain `PHPUnit\Framework\TestCase`s — `setUp`/`tearDown`,
`setUpBeforeClass`/`tearDownAfterClass`, `#[DataProvider]`, assertions and
`markTestSkipped()` all work as usual:

```php
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as Co;

class HealthTest extends TestCase
{
    public function testServiceResponds(): void
    {
        Co::sleep(0.5); // e.g. an async HTTP call to a service
        $this->assertTrue(true);
    }
}
```

The runner drives each test's lifecycle directly instead of going through
PHPUnit's sequential runner, so process-global features (output-buffering
assertions, global-state isolation, separate-process tests) are out of scope —
it is built for coroutine-friendly integration tests that assert and skip.

## Development

### Run Tests

```bash
composer install --ignore-platform-reqs
composer test
```

### Code Formatting

```bash
composer format
```

### Static Analysis

```bash
composer check
```

### Linting

```bash
composer lint
```

## License

MIT License. See [LICENSE](LICENSE) for more information.
