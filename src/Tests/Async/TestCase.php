<?php

namespace Utopia\Tests\Async;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Utopia\Tests\Extensions\Async;

/**
 * Convenience base class for tests run by the coroutine {@see Runner}.
 *
 * Extending this is optional — the Runner accepts any PHPUnit TestCase — but it
 * pulls in the {@see Async} trait so `assertEventually()` is available out of
 * the box. Combined with the Runner's hook-all, its polling waits yield to other
 * coroutines instead of blocking the scheduler.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use Async;
}
