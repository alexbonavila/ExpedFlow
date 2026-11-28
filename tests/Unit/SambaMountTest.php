<?php

use App\Support\Samba\Failure;
use App\Support\Samba\LinuxMountVerifier;
use App\Support\Samba\MountedShare;
use App\Support\Samba\StorageFailure;
use App\Support\Samba\TemplateSnapshot;
use Tests\Support\SambaFixture;

it('requires an exact writable hardened CIFS mount with the configured source', function (): void {
    $fixture = new SambaFixture;
    try {
        $configuration = $fixture->configuration(['mountpoint' => '/mnt/fixture space']);
        $mount = '37 21 0:51 / /mnt/fixture\040space rw,nosuid,nodev,noexec - cifs //fixture.local/cases rw,vers=3.1.1,cache=none';
        expect(LinuxMountVerifier::verify($configuration, $mount."\n"))->toBe(37);
        expect(LinuxMountVerifier::verify($configuration, str_replace('cifs', 'smb3', $mount)))->toBe(37);
        foreach ([
            '', 'bad line', str_replace('37 ', 'x ', $mount), str_replace('0:51', 'bad', $mount),
            str_replace('cifs', 'ext4', $mount), str_replace('fixture.local', 'wrong.local', $mount),
            str_replace('noexec', 'exec', $mount), str_replace('cache=none', 'cache=loose', $mount),
            $mount.',nostrictsync', str_replace('rw,vers', 'ro,vers', $mount),
            str_replace('rw,nosuid', 'ro,nosuid', $mount), str_replace(' / /mnt', ' /other /mnt', $mount),
            str_replace('\040space', '\141space', $mount), str_replace('\040space', '', $mount),
            $mount."\n".str_replace(['37 ', '\040space '], ['38 ', '\040space/sub '], $mount),
            $mount."\n".str_replace(['37 ', '/mnt/fixture\040space'], ['38 ', '/mnt/alias'], $mount),
            $mount."\n".str_replace(['37 ', '0:51'], ['38 ', '0:52'], $mount),
        ] as $invalid) {
            expect(fn () => LinuxMountVerifier::verify($configuration, $invalid))
                ->toThrow(StorageFailure::class, Failure::Unavailable->value);
        }
    } finally {
        $fixture->clean();
    }
});

it('refuses a locally existing root or a verifier mount id that does not own the open descriptor', function (): void {
    $fixture = new SambaFixture;
    try {
        expect(fn () => new MountedShare($fixture->configuration(), new LinuxMountVerifier))
            ->toThrow(StorageFailure::class, Failure::Unavailable->value);
        $fixture->id++;
        expect(fn () => new MountedShare($fixture->configuration(), $fixture))
            ->toThrow(StorageFailure::class, Failure::Unavailable->value);
        expect(scandir($fixture->root))->toBe(['.', '..']);
    } finally {
        $fixture->clean();
    }
});

it('streams an exclusive file into the pinned directory and verifies readback', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        $share = new MountedShare($fixture->configuration(), $fixture);
        $parent = $share->directory('EXPEDIENTES');
        expect($parent)->not->toBeNull();
        $case = $share->createDirectory($parent, '202600105 Àrea');
        $input = SambaFixture::stream('Document');
        expect($share->write($case, 'Acta.pdf', $input, 8))->toBe(hash('sha256', 'Document'));
        fclose($input);
        expect(iterator_to_array($share->names($case)))->toBe(['Acta.pdf']);
        expect($share->digest($case, 'Acta.pdf', 8))->toBe(hash('sha256', 'Document'));
        $input = SambaFixture::stream('Different');
        expect(fn () => $share->write($case, 'Acta.pdf', $input, 9, Failure::NameConflict))
            ->toThrow(StorageFailure::class, Failure::NameConflict->value);
        fclose($input);
        expect(file_get_contents($fixture->root.'/EXPEDIENTES/202600105 Àrea/Acta.pdf'))->toBe('Document');
        expect(fn () => $share->createDirectory($parent, '202600105 Àrea'))
            ->toThrow(StorageFailure::class, Failure::Conflict->value);
    } finally {
        $fixture->clean();
    }
});

it('retains a partial copy and refuses unsafe paths, symlinks and a changed mount', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        symlink($fixture->root, $fixture->root.'/EXPEDIENTES/link');
        $share = new MountedShare($fixture->configuration(), $fixture);
        $parent = $share->directory('EXPEDIENTES');
        $input = SambaFixture::stream(str_repeat('a', 1048577));
        expect(fn () => $share->write($parent, 'partial.pdf', $input, 1048578))
            ->toThrow(StorageFailure::class, Failure::Transfer->value);
        fclose($input);
        expect(filesize($fixture->root.'/EXPEDIENTES/partial.pdf'))->toBe(1048577);
        expect(fn () => $share->directory('EXPEDIENTES/link'))->toThrow(StorageFailure::class, Failure::Unsafe->value);
        expect(fn () => $share->directory('EXPEDIENTES/../other'))->toThrow(StorageFailure::class, Failure::Unsafe->value);
        expect(fn () => $share->directory('EXPEDIENTES/partial.pdf'))->toThrow(StorageFailure::class, Failure::Unsafe->value);
        expect($share->directory('EXPEDIENTES/missing'))->toBeNull();

        rename($fixture->root, $fixture->root.'-original');
        mkdir($fixture->root);
        $fixture->id++;
        $input = SambaFixture::stream('new');
        expect(fn () => $share->write($parent, 'new.pdf', $input, 3))
            ->toThrow(StorageFailure::class, Failure::Unavailable->value);
        fclose($input);
        expect(scandir($fixture->root))->toBe(['.', '..']);
    } finally {
        $fixture->clean();
    }
});

it('pins a checked parent instead of writing through its replacement path', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        $share = new MountedShare($fixture->configuration(), $fixture);
        $parent = $share->directory('EXPEDIENTES');
        rename($fixture->root, $fixture->root.'-original');
        mkdir($fixture->root.'/EXPEDIENTES', 0700, true);

        $input = SambaFixture::stream('pinned');
        $share->write($parent, 'pinned.pdf', $input, 6);
        fclose($input);

        expect(file_exists($fixture->root.'/EXPEDIENTES/pinned.pdf'))->toBeFalse()
            ->and(file_get_contents($fixture->root.'-original/EXPEDIENTES/pinned.pdf'))->toBe('pinned');
    } finally {
        $fixture->clean();
    }
});

it('captures nested files and empty directories in a deterministic bounded template snapshot', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05/nested/empty', 0700, true);
        file_put_contents($fixture->root.'/PLANTILLAS/05/nested/42', '12345');
        file_put_contents($fixture->root.'/PLANTILLAS/05/empty.txt', '');

        $share = new MountedShare($fixture->configuration(), $fixture);
        $snapshot = TemplateSnapshot::capture($share, 'PLANTILLAS/05');
        expect($snapshot->entries)->toBe([
            '/empty.txt' => ['size' => 0, 'digest' => hash('sha256', '')],
            '/nested' => null,
            '/nested/42' => ['size' => 5, 'digest' => hash('sha256', '12345')],
            '/nested/empty' => null,
        ]);
        expect(TemplateSnapshot::capture($share, 'PLANTILLAS/05')->digest)->toBe($snapshot->digest);
        foreach (['copy_entries' => 3, 'copy_file_bytes' => 4, 'copy_bytes' => 4, 'copy_depth' => 1] as $key => $limit) {
            $limited = new MountedShare($fixture->configuration([$key => $limit]), $fixture);
            expect(fn () => TemplateSnapshot::capture($limited, 'PLANTILLAS/05'))
                ->toThrow(StorageFailure::class, Failure::CopyLimit->value);
        }

        file_put_contents($fixture->root.'/PLANTILLAS/05/EMPTY.TXT', '');
        expect(fn () => TemplateSnapshot::capture($share, 'PLANTILLAS/05'))
            ->toThrow(StorageFailure::class, Failure::Conflict->value);
    } finally {
        $fixture->clean();
    }
});
