<?php

use App\Support\PublicStorageUrl;

it('builds the public storage URL from the configured application URL', function (mixed $url, string $expected) {
    expect(PublicStorageUrl::from($url))->toBe($expected);
})->with([
    'custom HTTPS origin with a subdirectory' => ['https://expedflow.example/team///', 'https://expedflow.example/team/storage'],
    'unset application URL' => [null, 'http://localhost/storage'],
    'non-string environment value' => [false, 'http://localhost/storage'],
]);
