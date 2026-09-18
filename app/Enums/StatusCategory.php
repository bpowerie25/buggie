<?php

namespace App\Enums;

/**
 * The fixed spine behind per-project status names.
 *
 * A project may rename "In Progress" to "Building" or add "Ready for QA", but every
 * status maps to one of these. That is what keeps "is this issue open?" a reliable
 * query instead of a growing list of string comparisons.
 */
enum StatusCategory: string
{
    case Backlog = 'backlog';
    case Unstarted = 'unstarted';
    case Started = 'started';
    case Done = 'done';
    case Canceled = 'canceled';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Backlog, self::Unstarted, self::Started => true,
            self::Done, self::Canceled => false,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, array{value: string, label: string, open: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'open' => $c->isOpen(),
        ], self::cases());
    }
}
