<?php

namespace App\Support\Samba;

enum Failure: string
{
    case Unavailable = 'storage_unavailable';
    case Configuration = 'storage_configuration_invalid';
    case Unsafe = 'storage_unsafe';
    case TemplateMissing = 'storage_template_missing';
    case Conflict = 'storage_conflict';
    case DirectoryLimit = 'storage_directory_limit';
    case CopyLimit = 'storage_copy_limit';
    case Transfer = 'storage_transfer_failed';
    case Busy = 'storage_busy';
    case NotReady = 'storage_not_ready';
    case NameConflict = 'upload_name_conflict';
    case AdmissionChanged = 'storage_admission_changed';

    public function status(): int
    {
        return match ($this) {
            self::Unavailable, self::Configuration, self::Transfer => 503,
            default => 409,
        };
    }
}
