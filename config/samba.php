<?php

return [
    'mountpoint' => env('SAMBA_MOUNTPOINT'),
    'source' => env('SAMBA_MOUNT_SOURCE'),
    'templates' => env('SAMBA_TEMPLATE_ROOT', 'PLANTILLAS'),
    'cases' => env('SAMBA_CASE_ROOT', 'EXPEDIENTES'),
    'upload_bytes' => env('SAMBA_UPLOAD_BYTES', '26214400'),
    'upload_entries' => env('SAMBA_UPLOAD_DIRECTORY_ENTRIES', '10000'),
    'uploads_per_minute' => env('SAMBA_UPLOADS_PER_MINUTE', '12'),
    'copy_file_bytes' => env('SAMBA_COPY_FILE_BYTES', '268435456'),
    'copy_bytes' => env('SAMBA_COPY_BYTES', '1073741824'),
    'copy_entries' => env('SAMBA_COPY_ENTRIES', '5000'),
    'copy_depth' => env('SAMBA_COPY_DEPTH', '16'),
    'operation_seconds' => env('SAMBA_OPERATION_SECONDS', '45'),
];
