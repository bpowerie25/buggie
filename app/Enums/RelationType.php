<?php

namespace App\Enums;

enum RelationType: string
{
    case Blocks = 'blocks';
    case BlockedBy = 'blocked_by';
    case RelatesTo = 'relates_to';
    case Duplicates = 'duplicates';

    /** The relation written on the other issue when this one is created. */
    public function inverse(): self
    {
        return match ($this) {
            self::Blocks => self::BlockedBy,
            self::BlockedBy => self::Blocks,
            self::RelatesTo => self::RelatesTo,
            self::Duplicates => self::Duplicates,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocks => 'Blocks',
            self::BlockedBy => 'Blocked by',
            self::RelatesTo => 'Relates to',
            self::Duplicates => 'Duplicates',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
