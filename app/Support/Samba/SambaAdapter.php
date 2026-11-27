<?php

namespace App\Support\Samba;

use Closure;

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
        $key = config()->string('app.key');
        if (strlen($key) < 32) {
            throw new StorageFailure(Failure::Configuration);
        }

        $share = new MountedShare($configuration, $this->verifier);
        $cases = $share->directory($configuration->cases) ?? throw new StorageFailure(Failure::NotReady);
        $destination = $share->directory($intent->target);
        [$phase, $claim] = $this->state($state);
        $template = $intent->template($configuration);
        $identity = json_encode([$configuration->source, $template, $intent->id, $intent->code, $intent->target], JSON_THROW_ON_ERROR);
        $ready = hash_hmac('sha256', 'ready:'.$identity.':'.$claim, $key);

        if ($destination !== null) {
            if ($claim === null || ! $this->matches($share, $destination, self::CLAIM, $claim)) {
                throw new StorageFailure(Failure::Conflict);
            }
            if ($this->matches($share, $destination, self::READY, $ready)) {
                if ($phase !== 'r') {
                    throw new StorageFailure(Failure::Conflict);
                }

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
