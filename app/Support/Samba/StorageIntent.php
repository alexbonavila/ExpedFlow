<?php

namespace App\Support\Samba;

final readonly class StorageIntent
{
    public function __construct(public int $id, public string $code, public string $target) {}

    public static function target(string $code, string $name, mixed $cases): string
    {
        $root = is_string($cases) && StoragePath::isRoot($cases) ? $cases : StorageConfiguration::DEFAULT_CASES_ROOT;

        return $root.'/'.StoragePath::caseComponent($code, $name);
    }

    public function validate(StorageConfiguration $configuration): void
    {
        $name = basename($this->target);
        if (preg_match('/^[0-9]{9}$/D', $this->code) !== 1
            || ! str_starts_with($name, $this->code.' ') || ! StoragePath::isUserComponent($name)
            || $this->target !== $configuration->cases.'/'.$name) {
            throw new StorageFailure(Failure::Unsafe);
        }
    }

    public function template(StorageConfiguration $configuration): string
    {
        return $configuration->templates.'/'.substr($this->code, -2);
    }
}
