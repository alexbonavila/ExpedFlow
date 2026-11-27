<?php

namespace App\Support\Samba;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class UploadInput
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public static function validate(UploadedFile $file, int $maximum): int
    {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw self::invalid('upload_too_large')->status(413);
        }
        if (! $file->isValid()) {
            throw self::invalid('upload_invalid');
        }
        $name = $file->getClientOriginalPath();
        $type = self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        if (! StoragePath::isUserComponent($name) || $type === null) {
            throw self::invalid('upload_invalid_name');
        }
        $path = $file->getRealPath();
        $stat = $path === false ? false : @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] <= 0 || $file->isLink()) {
            throw self::invalid('upload_invalid');
        }
        if ($stat['size'] > $maximum) {
            throw self::invalid('upload_too_large')->status(413);
        }

        $header = @file_get_contents($path, length: 8192);
        if ($header === false || (new \finfo(FILEINFO_MIME_TYPE))->buffer($header) !== $type) {
            throw self::invalid('upload_invalid_type');
        }
        if ($type === 'application/pdf') {
            $tail = @file_get_contents($path, offset: max(0, $stat['size'] - 1024), length: 1024);
            if (preg_match('/^%PDF-(?:1\.[0-7]|2\.0)[\r\n]/', $header) !== 1
                || $tail === false || preg_match('/%%EOF[ \t\r\n]*$/D', $tail) !== 1) {
                throw self::invalid('upload_invalid_type');
            }
        } else {
            $image = @getimagesizefromstring($header);
            if ($image === false || $image['mime'] !== $type || $image[0] < 1 || $image[1] < 1) {
                throw self::invalid('upload_invalid_type');
            }
        }

        return $stat['size'];
    }

    public static function invalid(string $code): ValidationException
    {
        return ValidationException::withMessages(['file' => __('samba.'.$code)]);
    }
}
