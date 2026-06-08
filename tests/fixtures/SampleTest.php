<?php

namespace Tests\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine as Co;
use Utopia\Tests\Async\TestCase;

/**
 * Fixture exercised by the coroutine Runner (excluded from the normal suite).
 *
 * Every test yields via Co::sleep, so under a concurrency >= the number of
 * tests they overlap: wall-clock stays near a single sleep, not their sum.
 */
class SampleTest extends TestCase
{
    public function testPassesAfterYield(): void
    {
        Co::sleep(0.2);
        $this->assertGreaterThanOrEqual(0, Co::getCid());
    }

    public function testRunsInsideCoroutine(): void
    {
        Co::sleep(0.2);
        $this->assertGreaterThan(0, Co::getCid());
    }

    #[DataProvider('numbers')]
    public function testDataProvider(int $n): void
    {
        Co::sleep(0.2);
        $this->assertGreaterThan(0, $n);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function numbers(): array
    {
        return ['one' => [1], 'two' => [2]];
    }

    public function testSkips(): void
    {
        $this->markTestSkipped('not today');
    }

    public function testFails(): void
    {
        Co::sleep(0.2);
        $this->assertSame(1, 2);
    }

    public function testErrors(): void
    {
        throw new \RuntimeException('boom');
    }

    public function testEventuallyConverges(): void
    {
        // assertEventually comes from the Async base class; its usleep polling
        // yields under the runner's hook-all instead of blocking the scheduler.
        $start = \microtime(true);

        self::assertEventually(function () use ($start) {
            $this->assertGreaterThan(0.1, \microtime(true) - $start);
        }, timeoutMs: 2000, waitMs: 50);
    }
}
