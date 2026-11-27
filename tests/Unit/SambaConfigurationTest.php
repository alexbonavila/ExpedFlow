<?php

use App\Support\Samba\Failure;
use App\Support\Samba\StorageConfiguration;
use App\Support\Samba\StorageFailure;
use App\Support\Samba\StorageIntent;
use App\Support\Samba\StoragePath;

/** @return array<string, mixed> */
function sambaConfigurationValues(): array
{
    return [
        'mountpoint' => '/mnt/cases', 'source' => '//fixture.local/cases',
        'templates' => 'PLANTILLAS', 'cases' => 'EXPEDIENTES', 'upload_bytes' => '26214400',
        'copy_file_bytes' => '268435456', 'copy_bytes' => '1073741824',
        'copy_entries' => '5000', 'copy_depth' => '16', 'operation_seconds' => '45',
    ];
}

it('keeps a code-qualified private intent and resolves the immutable type suffix', function (): void {
    $configuration = StorageConfiguration::from(sambaConfigurationValues(), '/srv/public');
    $target = StorageIntent::target('202600105', 'Àrea / obres\\ noves .', $configuration->cases);
    $intent = new StorageIntent(37, '202600105', $target);
    $intent->validate($configuration);

    expect($target)->toBe('EXPEDIENTES/202600105 Àrea _ obres_ noves')
        ->and($intent->template($configuration))->toBe('PLANTILLAS/05')
        ->and($configuration->uploadBytes)->toBe(26214400)
        ->and(StoragePath::caseComponent('202600105', '...'))->toBe('202600105 _')
        ->and(strlen(StoragePath::caseComponent('202600105', str_repeat('à', 300))))->toBe(254)
        ->and(StorageIntent::target('202600105', 'Name', '../invalid'))->toBe('EXPEDIENTES/202600105 Name');
});

it('rejects unsafe deployment settings before opening a share', function (string $field, mixed $value): void {
    expect(fn () => StorageConfiguration::from([$field => $value] + sambaConfigurationValues(), '/srv/public'))
        ->toThrow(StorageFailure::class, Failure::Configuration->value);
})->with([
    ['mountpoint', null], ['mountpoint', '/'], ['mountpoint', '/srv'],
    ['mountpoint', '/srv/public/mount'], ['mountpoint', '/mnt/../mount'],
    ['source', '//user@fixture.local/cases'], ['source', '//fixture.local/cases/nested'],
    ['templates', 'expedientes/nested'], ['cases', 'PLANTILLAS'], ['cases', 'AUX'],
    ['upload_bytes', '0'], ['copy_file_bytes', '1.5'], ['copy_bytes', true],
    ['copy_entries', 20001], ['copy_depth', '00'], ['operation_seconds', 121],
]);

it('rejects a persisted target outside the case scope or for a different code', function (string $target): void {
    $configuration = StorageConfiguration::from(sambaConfigurationValues(), '/srv/public');

    expect(fn () => (new StorageIntent(37, '202600105', $target))->validate($configuration))
        ->toThrow(StorageFailure::class, Failure::Unsafe->value);
})->with(['EXPEDIENTES/202600205 Name', 'OTHER/202600105 Name', 'EXPEDIENTES/../202600105 Name', 'EXPEDIENTES/202600105 Name.']);

it('rejects unsafe Windows names and the entire internal marker namespace', function (string $name): void {
    expect(StoragePath::isUserComponent($name))->toBeFalse();
})->with(['', '..', 'x/y', 'x\\y', 'AUX.png', 'lpt².txt', 'trailing ', "x\0y", '.EXPEDFLOW-READY', 'bad:name']);

it('normalizes comparison of composed accents and preserves a Unicode basename', function (): void {
    expect(StoragePath::fold("E\u{0301}LÈVE.PDF"))->toBe(StoragePath::fold('élève.pdf'))
        ->and(StoragePath::isUserComponent('Acta de reunió.pdf'))->toBeTrue()
        ->and(fn () => StoragePath::caseComponent('wrong', 'Acta'))->toThrow(StorageFailure::class, Failure::Unsafe->value);
});
