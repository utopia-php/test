<?php

namespace Utopia\Tests\Async;

enum Status: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Skipped = 'skipped';

    public function symbol(): string
    {
        return match ($this) {
            self::Passed => '.',
            self::Failed => 'F',
            self::Errored => 'E',
            self::Skipped => 'S',
        };
    }
}
