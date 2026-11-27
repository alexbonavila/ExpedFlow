<?php

namespace Tests\Support;

use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class SambaFixture implements MountVerifier
{
    public readonly string $root;

    public int $id;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/expedflow-i-'.bin2hex(random_bytes(8));
        if (! mkdir($this->root, 0700)) {
            throw new RuntimeException('Could not create synthetic storage fixture.');
        }
        $fd = fopen($this->root, 'rb');
        $this->id = 0;
        foreach (glob('/proc/self/fd/*') ?: [] as $path) {
            if (@readlink($path) === $this->root) {
                $info = file_get_contents('/proc/self/fdinfo/'.basename($path));
                if (is_string($info) && preg_match('/^mnt_id:\s+(\d+)$/m', $info, $match) === 1) {
                    $this->id = (int) $match[1];
                }
            }
        }
        if (is_resource($fd)) {
            fclose($fd);
        }
        if ($this->id === 0) {
            throw new RuntimeException('Synthetic filesystem mount could not be identified.');
        }
    }

    /** @param array<string, mixed> $overrides */
    public function configuration(array $overrides = []): StorageConfiguration
    {
        return StorageConfiguration::from($overrides + $this->settings(), '/srv/public');
    }

    /** @return array<string, int|string> */
    public function settings(): array
    {
        return [
            'mountpoint' => $this->root, 'source' => '//fixture.local/cases',
            'templates' => 'PLANTILLAS', 'cases' => 'EXPEDIENTES', 'upload_bytes' => 26214400,
            'copy_file_bytes' => 26214400, 'copy_bytes' => 52428800,
            'copy_entries' => 100, 'copy_depth' => 5, 'operation_seconds' => 20,
        ];
    }

    public function mountId(StorageConfiguration $configuration): int
    {
        return $this->id;
    }

    public function clean(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        (new Filesystem)->deleteDirectory($this->root.'-original');
    }

    /** @return resource */
    public static function stream(string $value): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open synthetic stream.');
        }
        fwrite($stream, $value);
        rewind($stream);

        return $stream;
    }
}
