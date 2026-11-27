<?php

namespace App\Support\Samba;

final readonly class StorageConfiguration
{
    public const DEFAULT_CASES_ROOT = 'EXPEDIENTES';

    private function __construct(
        public string $mountpoint,
        public string $source,
        public string $templates,
        public string $cases,
        public int $uploadBytes,
        public int $copyFileBytes,
        public int $copyBytes,
        public int $copyEntries,
        public int $copyDepth,
        public int $seconds,
    ) {}

    /** @param array<string, mixed> $values */
    public static function from(array $values, string $documentRoot): self
    {
        $mount = $values['mountpoint'] ?? null;
        $source = $values['source'] ?? null;
        $templates = $values['templates'] ?? null;
        $cases = $values['cases'] ?? null;
        $public = realpath($documentRoot) ?: $documentRoot;

        if (! is_string($mount) || ! str_starts_with($mount, '/') || strlen($mount) > 1024
            || ! StoragePath::isRoot(substr($mount, 1))
            || self::overlaps($mount, $public) || self::overlaps($mount, $documentRoot)
            || ! is_string($source) || preg_match('~^//[a-zA-Z0-9.\[\]:_-]+/[^/\\\\@\p{Cc}]+$~uD', $source) !== 1
            || ! is_string($templates) || ! StoragePath::isRoot($templates)
            || ! is_string($cases) || ! StoragePath::isRoot($cases)
            || self::overlaps(StoragePath::fold($templates), StoragePath::fold($cases))) {
            throw new StorageFailure(Failure::Configuration);
        }

        return new self(
            $mount, $source, $templates, $cases,
            self::limit($values, 'upload_bytes', 268435456),
            self::limit($values, 'copy_file_bytes', 4294967296),
            self::limit($values, 'copy_bytes', 17179869184),
            self::limit($values, 'copy_entries', 20000),
            self::limit($values, 'copy_depth', 32),
            self::limit($values, 'operation_seconds', 120),
        );
    }

    private static function overlaps(string $first, string $second): bool
    {
        return $first === $second || str_starts_with($first, $second.'/') || str_starts_with($second, $first.'/');
    }

    /** @param array<string, mixed> $values */
    private static function limit(array $values, string $key, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if ((! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]{0,10}$/D', $value) !== 1))
            || (int) $value < 1 || (int) $value > $maximum) {
            throw new StorageFailure(Failure::Configuration);
        }

        return (int) $value;
    }
}
