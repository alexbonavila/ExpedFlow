<?php

namespace App\Support\Samba;

use Closure;
use Illuminate\Http\UploadedFile;

final class SambaAdapter
{
    private const CLAIM = '.expedflow-claim-v1';

    private const READY = '.expedflow-ready-v1';

    public function __construct(private readonly MountVerifier $verifier) {}

    public function configuration(): StorageConfiguration
    {
        return StorageConfiguration::from(config()->array('samba'), public_path());
    }

    /** @param Closure(string): void $saveState External I/O never occurs within this callback. */
    public function provision(StorageIntent $intent, ?string $state, Closure $saveState): void
    {
        $configuration = $this->configuration();
        $intent->validate($configuration);
        $key = $this->key();

        $share = new MountedShare($configuration, $this->verifier);
        $cases = $share->directory($configuration->cases) ?? throw new StorageFailure(Failure::NotReady);
        $destination = $share->directory($intent->target);
        [$phase, $claim] = $this->state($state);
        $template = $intent->template($configuration);
        $identity = $this->identity($configuration, $intent);
        $ready = hash_hmac('sha256', 'ready:'.$identity.':'.$claim, $key);

        if ($destination !== null) {
            if ($claim === null || ! $this->matches($share, $destination, self::CLAIM, $claim)) {
                throw new StorageFailure(Failure::Conflict);
            }
            if ($this->matches($share, $destination, self::READY, $ready)) {
                if ($phase !== 'r') {
                    throw new StorageFailure(Failure::Conflict);
                }

                $share->check();

                return;
            }
        } elseif ($phase === 'r') {
            throw new StorageFailure(Failure::NotReady);
        }

        $snapshot = TemplateSnapshot::capture($share, $template);
        $expectedClaim = hash_hmac('sha256', 'claim:'.$identity.':'.$snapshot->digest, $key);
        if ($claim !== null && ! hash_equals($claim, $expectedClaim)) {
            throw new StorageFailure(Failure::Conflict);
        }
        if ($claim === null) {
            $claim = $expectedClaim;
            $saveState('p:'.$claim);
        }
        if ($destination === null) {
            $destination = $share->createDirectory($cases, basename($intent->target));
            $this->writeMarker($share, $destination, self::CLAIM, $claim);
        } else {
            $existing = TemplateSnapshot::capture($share, $intent->target, self::CLAIM);
            foreach ($existing->entries as $path => $item) {
                if (! array_key_exists($path, $snapshot->entries) || $item !== $snapshot->entries[$path]) {
                    throw new StorageFailure(Failure::Conflict);
                }
            }
        }

        $this->copy($share, $template, $intent->target, $snapshot);
        if (! hash_equals($snapshot->digest, TemplateSnapshot::capture($share, $template)->digest)
            || ! hash_equals($snapshot->digest, TemplateSnapshot::capture($share, $intent->target, self::CLAIM)->digest)) {
            throw new StorageFailure(Failure::Conflict);
        }
        // Reserve readiness first so a formerly ready missing directory is never recreated.
        $saveState('r:'.$claim);
        $ready = hash_hmac('sha256', 'ready:'.$identity.':'.$claim, $key);
        $this->writeMarker($share, $destination, self::READY, $ready);
        $share->check();
    }

    public function upload(StorageIntent $intent, ?string $state, UploadedFile $file, int $size): void
    {
        $configuration = $this->configuration();
        if ($size < 1 || $size > $configuration->uploadBytes) {
            throw UploadInput::invalid('upload_too_large')->status(413);
        }
        $intent->validate($configuration);
        [$phase, $claim] = $this->state($state);
        if ($phase !== 'r' || $claim === null) {
            throw new StorageFailure(Failure::NotReady);
        }

        $identity = $this->identity($configuration, $intent);
        $ready = hash_hmac('sha256', 'ready:'.$identity.':'.$claim, $this->key());
        $share = new MountedShare($configuration, $this->verifier);
        $directory = $share->directory($intent->target) ?? throw new StorageFailure(Failure::NotReady);
        if (! $this->matches($share, $directory, self::CLAIM, $claim) || ! $this->matches($share, $directory, self::READY, $ready)) {
            throw new StorageFailure(Failure::NotReady);
        }

        $name = $file->getClientOriginalPath();
        $folded = StoragePath::fold($name);
        $count = 0;
        foreach ($share->names($directory) as $existing) {
            if (++$count > $configuration->uploadEntries) {
                throw new StorageFailure(Failure::DirectoryLimit);
            }
            if (StoragePath::fold($existing) === $folded) {
                throw new StorageFailure(Failure::NameConflict);
            }
        }
        $path = $file->getRealPath();
        $input = $path === false ? false : @fopen($path, 'rb');
        if ($input === false) {
            throw UploadInput::invalid('upload_invalid');
        }
        try {
            $share->write($directory, $name, $input, $size, Failure::NameConflict);
        } finally {
            if (! @fclose($input)) {
                throw new StorageFailure(Failure::Transfer);
            }
        }
    }

    private function key(): string
    {
        $key = config()->string('app.key');
        if (strlen($key) < 32) {
            throw new StorageFailure(Failure::Configuration);
        }

        return $key;
    }

    private function identity(StorageConfiguration $configuration, StorageIntent $intent): string
    {
        return json_encode([
            $configuration->source, $intent->template($configuration), $intent->id, $intent->code, $intent->target,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{string|null, string|null} */
    private function state(?string $state): array
    {
        if ($state === null) {
            return [null, null];
        }
        if (preg_match('/^([pr]):([a-f0-9]{64})$/D', $state, $parts) !== 1) {
            throw new StorageFailure(Failure::Conflict);
        }

        return [$parts[1], $parts[2]];
    }

    private function matches(MountedShare $share, PinnedDirectory $parent, string $name, string $expected): bool
    {
        $entry = $share->entry($parent, $name);
        if ($entry === null) {
            return false;
        }
        if ($entry['mode'] !== 0100000 || $entry['size'] !== strlen($expected)
            || ! hash_equals(hash('sha256', $expected), $share->digest($parent, $name, strlen($expected)))) {
            throw new StorageFailure(Failure::Conflict);
        }

        return true;
    }

    private function writeMarker(MountedShare $share, PinnedDirectory $directory, string $name, string $value): void
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new StorageFailure(Failure::Transfer);
        }
        try {
            if (fwrite($stream, $value) !== strlen($value) || ! rewind($stream)) {
                throw new StorageFailure(Failure::Transfer);
            }
            $share->write($directory, $name, $stream, strlen($value));
        } finally {
            fclose($stream);
        }
    }

    private function copy(MountedShare $share, string $template, string $target, TemplateSnapshot $snapshot): void
    {
        foreach ($snapshot->entries as $suffix => $file) {
            $parent = $share->directory(dirname($target.$suffix)) ?? throw new StorageFailure(Failure::Conflict);
            $name = basename($suffix);
            $existing = $share->entry($parent, $name);
            if ($file === null) {
                if ($existing === null) {
                    $share->createDirectory($parent, $name);
                } elseif ($existing['mode'] !== 0040000) {
                    throw new StorageFailure(Failure::Conflict);
                }

                continue;
            }
            if ($existing !== null) {
                if ($existing['mode'] !== 0100000 || $existing['size'] !== $file['size']
                    || ! hash_equals($file['digest'], $share->digest($parent, $name, $file['size']))) {
                    throw new StorageFailure(Failure::Conflict);
                }

                continue;
            }
            $source = $share->directory(dirname($template.$suffix)) ?? throw new StorageFailure(Failure::TemplateMissing);
            $input = $share->read($source, $name);
            try {
                $digest = $share->write($parent, $name, $input, $file['size']);
                if (! hash_equals($file['digest'], $digest)) {
                    throw new StorageFailure(Failure::Conflict);
                }
            } finally {
                if (! @fclose($input)) {
                    throw new StorageFailure(Failure::Transfer);
                }
            }
        }
    }
}
