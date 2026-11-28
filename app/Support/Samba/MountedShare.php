<?php

namespace App\Support\Samba;

use FilesystemIterator;
use Generator;
use UnexpectedValueException;

final class MountedShare
{
    private readonly int $deadline;

    private readonly PinnedDirectory $root;

    public function __construct(
        public readonly StorageConfiguration $configuration,
        private readonly MountVerifier $verifier,
    ) {
        $this->deadline = hrtime(true) + $configuration->seconds * 1_000_000_000;
        $id = $verifier->mountId($configuration);
        $this->root = PinnedDirectory::open($configuration->mountpoint, $id);
        $this->check();
    }

    public function check(): void
    {
        if (hrtime(true) >= $this->deadline) {
            throw new StorageFailure(Failure::Transfer);
        }
        if ($this->root->mountId !== $this->verifier->mountId($this->configuration)) {
            throw new StorageFailure(Failure::Unavailable);
        }
    }

    public function directory(string $path): ?PinnedDirectory
    {
        $this->check();
        if (strlen($path) > 3500) {
            throw new StorageFailure(Failure::Unsafe);
        }
        $directory = $this->root;
        foreach (explode('/', $path) as $name) {
            $item = $this->entry($directory, $name);
            if ($item === null) {
                return null;
            }
            if ($item['mode'] !== 0040000) {
                throw new StorageFailure(Failure::Unsafe);
            }
            $directory = PinnedDirectory::open($directory->path.'/'.$name, $this->root->mountId);
        }

        return $directory;
    }

    public function createDirectory(PinnedDirectory $parent, string $name): PinnedDirectory
    {
        $path = $this->path($parent, $name);
        $this->check();
        if (! @mkdir($path, 0770)) {
            throw new StorageFailure($this->entry($parent, $name) === null ? Failure::Transfer : Failure::Conflict);
        }
        $this->check();

        return PinnedDirectory::open($path, $this->root->mountId);
    }

    /** @return array{mode:int, size:int, ino:int}|null */
    public function entry(PinnedDirectory $parent, string $name): ?array
    {
        $path = $this->path($parent, $name);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            $this->check();

            return null;
        }
        $mode = $stat['mode'] & 0170000;
        if ($stat['dev'] !== $this->root->device || ! in_array($mode, [0040000, 0100000], true)) {
            throw new StorageFailure(Failure::Unsafe);
        }

        return ['mode' => $mode, 'size' => $stat['size'], 'ino' => $stat['ino']];
    }

    /** @return Generator<int, string> */
    public function names(PinnedDirectory $directory): Generator
    {
        $this->check();
        try {
            foreach (new FilesystemIterator($directory->path, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS) as $path) {
                $this->check();
                yield basename($path);
            }
        } catch (UnexpectedValueException) {
            throw new StorageFailure(Failure::Transfer);
        }
    }

    /** @return resource */
    public function read(PinnedDirectory $parent, string $name): mixed
    {
        $entry = $this->entry($parent, $name);
        if ($entry === null || $entry['mode'] !== 0100000) {
            throw new StorageFailure(Failure::Conflict);
        }

        $stream = @fopen($this->path($parent, $name), 'rb');
        if ($stream === false) {
            throw new StorageFailure(Failure::Transfer);
        }
        try {
            $stat = @fstat($stream);
            if ($stat === false || $stat['dev'] !== $this->root->device || $stat['ino'] !== $entry['ino']
                || ($stat['mode'] & 0170000) !== 0100000) {
                throw new StorageFailure(Failure::Unsafe);
            }
            $this->check();

            return $stream;
        } catch (StorageFailure $failure) {
            @fclose($stream);
            throw $failure;
        }
    }

    /** @param resource|null $output */
    public function digest(PinnedDirectory $parent, string $name, int $size, mixed $output = null): string
    {
        $input = $this->read($parent, $name);
        try {
            return $this->transfer($input, $size, $output);
        } finally {
            self::close($input);
        }
    }

    /** @param resource $input */
    public function write(PinnedDirectory $parent, string $name, mixed $input, int $size, Failure $collision = Failure::Conflict): string
    {
        $path = $this->path($parent, $name);
        $this->check();
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            $this->check();
            throw new StorageFailure($this->entry($parent, $name) === null ? Failure::Transfer : $collision);
        }
        try {
            $stat = @fstat($stream);
            $entry = $this->entry($parent, $name);
            if ($stat === false || $stat['dev'] !== $this->root->device || $entry === null
                || $stat['ino'] !== $entry['ino'] || $entry['mode'] !== 0100000) {
                throw new StorageFailure(Failure::Unsafe);
            }
            $digest = $this->transfer($input, $size, $stream);
            if (! @fflush($stream) || ! @fsync($stream)) {
                throw new StorageFailure(Failure::Transfer);
            }
        } finally {
            self::close($stream);
        }
        $this->check();
        if (! hash_equals($digest, $this->digest($parent, $name, $size))) {
            throw new StorageFailure(Failure::Transfer);
        }
        $this->check();

        return $digest;
    }

    /** @param resource $stream */
    private static function close(mixed $stream): void
    {
        if (! @fclose($stream)) {
            throw new StorageFailure(Failure::Transfer);
        }
    }

    /**
     * @param  resource  $input
     * @param  resource|null  $output
     */
    private function transfer(mixed $input, int $size, mixed $output): string
    {
        $hash = hash_init('sha256');
        $remaining = $size;
        while ($remaining > 0) {
            $this->check();
            $chunk = @fread($input, min($remaining, 1048576));
            if ($chunk === false || $chunk === '') {
                throw new StorageFailure(Failure::Transfer);
            }
            $count = strlen($chunk);
            if ($output !== null && @fwrite($output, $chunk) !== $count) {
                throw new StorageFailure(Failure::Transfer);
            }
            hash_update($hash, $chunk);
            $remaining -= $count;
        }
        if (@fread($input, 1) !== '' || ! feof($input)) {
            throw new StorageFailure(Failure::Transfer);
        }
        $this->check();

        return hash_final($hash);
    }

    private function path(PinnedDirectory $parent, string $name): string
    {
        if ($parent->mountId !== $this->root->mountId || $parent->device !== $this->root->device
            || ! StoragePath::isComponent($name)) {
            throw new StorageFailure(Failure::Unsafe);
        }

        return $parent->path.'/'.$name;
    }
}
