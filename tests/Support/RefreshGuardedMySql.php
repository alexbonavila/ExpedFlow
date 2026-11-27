<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshGuardedMySql
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        TestingDatabase::assertSafe($this->app);
    }
}
