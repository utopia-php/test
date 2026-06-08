<?php

namespace Utopia\Tests\Async;

final readonly class Result
{
    public function __construct(
        public string $name,
        public Status $status,
        public string $message = '',
    ) {
    }
}
