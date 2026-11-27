<?php

namespace App\Support\Samba;

final readonly class TemplateSnapshot
{
    /** @param array<string, array{size: int, digest: string}|null> $entries Null denotes an empty or populated directory. */
    private function __construct(public array $entries, public string $digest) {}

    public static function capture(MountedShare $share, string $root, string ...$privateMarkers): self
    {
        $entries = [];
        $bytes = 0;
        $dir = $share->directory($root) ?? throw new StorageFailure(Failure::TemplateMissing);
        self::collect($share, $root, $dir, '', 1, $entries, $bytes, array_values($privateMarkers));
        ksort($entries, SORT_STRING);
        $digest = hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));

        return new self($entries, $digest);
    }

    /**
     * @param  array<string, array{size: int, digest: string}|null>  $entries
     * @param  list<string>  $markers
     */
    private static function collect(
        MountedShare $share,
        string $root,
        PinnedDirectory $dir,
        string $prefix,
        int $depth,
        array &$entries,
        int &$bytes,
        array $markers,
    ): void {
        $limits = $share->configuration;
        $names = [];
        foreach ($share->names($dir) as $name) {
            if ($prefix === '' && in_array($name, $markers, true)) {
                continue;
            }
            if (! StoragePath::isUserComponent($name)) {
                throw new StorageFailure(Failure::Unsafe);
            }

            $path = $prefix.$name;
            if ($depth > $limits->copyDepth || count($entries) >= $limits->copyEntries
                || strlen($root.'/'.$path) > 3500) {
                throw new StorageFailure(Failure::CopyLimit);
            }
            $folded = StoragePath::fold($name);
            if (isset($names[$folded])) {
                throw new StorageFailure(Failure::Conflict);
            }
            $names[$folded] = true;
            $item = $share->entry($dir, $name) ?? throw new StorageFailure(Failure::Transfer);
            // A nonnumeric prefix preserves PHP string keys for names made only of digits.
            $key = '/'.$path;
            if ($item['mode'] === 0040000) {
                $entries[$key] = null;
                $child = $share->directory($root.'/'.$path) ?? throw new StorageFailure(Failure::Transfer);
                self::collect($share, $root, $child, $path.'/', $depth + 1, $entries, $bytes, []);
            } else {
                $size = $item['size'];
                $bytes += $size;
                if ($size < 0 || $size > $limits->copyFileBytes || $bytes > $limits->copyBytes) {
                    throw new StorageFailure(Failure::CopyLimit);
                }
                $entries[$key] = ['size' => $size, 'digest' => $share->digest($dir, $name, $size)];
            }
        }
    }
}
