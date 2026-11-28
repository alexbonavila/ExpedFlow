<?php

namespace App\Support\Audit;

use App\Support\PermissionKey;
use LogicException;

final class AuditChanges
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    public static function between(AuditSubject $subject, array $before, array $after): array
    {
        $fields = $subject->fields();
        $old = $attributes = [];

        foreach (array_keys($before + $after) as $field) {
            $type = $fields[$field] ?? throw new LogicException('Unknown audit field.');
            $previous = $before[$field] ?? null;
            $current = $after[$field] ?? null;

            if ($type !== 'redact') {
                $previous = self::value($type, $previous);
                $current = self::value($type, $current);
            }

            if ($previous === $current) {
                continue;
            }

            if ($type === 'redact') {
                $attributes[$field] = ['changed' => true];
            } else {
                $old[$field] = $previous;
                $attributes[$field] = $current;
            }
        }

        return ['old' => $old, 'attributes' => $attributes];
    }

    public static function value(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'text' => self::text($value),
            'locale' => self::matching($value, '/\A(?:es|ca|en)\z/'),
            'case_code' => self::matching($value, '/\A[0-9]{9}\z/'),
            'date' => self::matching($value, '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'),
            'time' => self::matching($value, '/\A[0-9]{2}:[0-9]{2}:[0-9]{2}\z/'),
            'id' => self::id($value),
            'flag' => match ($value) {
                true, 1 => true,
                false, 0 => false,
                default => throw new LogicException('Invalid audit flag.'),
            },
            'ids' => self::items($value, self::id(...)),
            'permissions' => self::items($value, self::permission(...)),
            'assignment_pairs' => self::items($value, fn (mixed $pair): string => self::matching($pair, '/\A[1-9][0-9]{0,19}:[1-9][0-9]{0,19}\z/')),
            'labels' => self::labels($value),
            'state' => in_array($value, ['active', 'trashed', 'deleted'], true) ? $value : throw new LogicException('Invalid audit state.'),
            default => throw new LogicException('Unknown audit field type.'),
        };
    }

    private static function id(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new LogicException('Invalid audit identifier.');
        }

        return $value;
    }

    private static function permission(mixed $value): string
    {
        if (! is_string($value) || PermissionKey::tryFrom($value) === null) {
            throw new LogicException('Invalid audit permission.');
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 255) {
            throw new LogicException('Invalid audit text.');
        }

        return $value;
    }

    private static function matching(mixed $value, string $pattern): string
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new LogicException('Invalid audit format.');
        }

        return $value;
    }

    /**
     * @template T of int|string
     *
     * @param  callable(mixed): T  $convert
     * @return list<T>
     */
    private static function items(mixed $value, callable $convert): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1000) {
            throw new LogicException('Invalid audit set.');
        }

        $items = array_values(array_unique(array_map($convert, $value)));
        sort($items);

        return $items;
    }

    /** @return array<string, string> */
    private static function labels(mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['es', 'ca', 'en']) !== []) {
            throw new LogicException('Invalid audit labels.');
        }

        $labels = array_map(self::text(...), $value);
        ksort($labels);

        return $labels;
    }
}
