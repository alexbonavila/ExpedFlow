<?php

namespace App\Support\Samba;

use Illuminate\Container\Attributes\Bind;

#[Bind(LinuxMountVerifier::class)]
interface MountVerifier
{
    public function mountId(StorageConfiguration $configuration): int;
}
