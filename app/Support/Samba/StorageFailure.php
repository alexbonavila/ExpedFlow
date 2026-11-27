<?php

namespace App\Support\Samba;

use RuntimeException;

final class StorageFailure extends RuntimeException
{
    public function __construct(public readonly Failure $reason)
    {
        parent::__construct($reason->value);
    }
}
