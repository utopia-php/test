<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Drives the coroutine runner as a subprocess against the SampleTest fixture.
 *
 * A subprocess keeps the runner's PHPUnit configuration bootstrap from clashing
 * with the configuration of the suite running this very test, and lets us turn
 * off Xdebug (which segfaults under Swoole coroutines).
 */
class RunnerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('The swoole extension is required for the coroutine runner.');
        }
    }

    public function testReportsEachOutcome(): void
    {
        [$exit, $output] = $this->runFixtures(concurrency: 10);

        // 3 plain + 2 data-set + 1 skip + 1 fail + 1 error = 8 cases.
        $this->assertMatchesRegularExpression('/Tests: 8, Assertions: \d+, Failures: 1, Errors: 1, Skipped: 1/', $output);
        $this->assertStringContainsString('SampleTest::testFails', $output);
        $this->assertStringContainsString('SampleTest::testErrors', $output);
        $this->assertStringContainsString('RuntimeException: boom', $output);
        $this->assertSame(1, $exit, 'a failing/erroring run must exit non-zero');
    }

    public function testRunsConcurrently(): void
    {
        // Six tests each sleep 0.2s. Serial ~1.2s; fully concurrent ~0.2s.
        [, , $serial] = $this->runFixtures(concurrency: 1);
        [, , $concurrent] = $this->runFixtures(concurrency: 10);

        $this->assertGreaterThan(2 * $concurrent, $serial, 'coroutines should overlap their waits');
    }

    /**
     * @return array{int, string, float} [exit code, output, elapsed milliseconds]
     */
    private function runFixtures(int $concurrency): array
    {
        $command = \sprintf(
            'php -d xdebug.mode=off %s %s --concurrency=%d 2>&1',
            \escapeshellarg(\dirname(__DIR__).'/bin/co-phpunit'),
            \escapeshellarg(\dirname(__DIR__).'/tests/fixtures'),
            $concurrency,
        );

        $start = \microtime(true);
        $output = (string) \shell_exec($command.'; echo "EXIT:$?"');
        $elapsed = (\microtime(true) - $start) * 1000;

        \preg_match('/EXIT:(\d+)/', $output, $matches);

        return [(int) ($matches[1] ?? -1), $output, $elapsed];
    }
}
