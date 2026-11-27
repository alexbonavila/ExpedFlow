<?php

namespace App\Support\Samba;

enum Operation: string
{
    case Provision = 'samba_provision';
    case Retry = 'samba_retry';
    case Upload = 'samba_upload';
}
