<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class HarnessJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $path) {}
}
