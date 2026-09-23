<?php

namespace App\Enums;

/**
 * When we will fix it.
 *
 * There was once a separate `severity` column for how bad a thing is when it happens —
 * a cosmetic typo on a pricing page being low severity and urgent priority. It was
 * never built beyond the column and has been dropped. The distinction is real and
 * worth having if anybody asks for it; it just needs a screen rather than a schema.
 */
enum IssuePriority: int
{
    case None = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Urgent = 4;

    public function label(): string
    {
        return $this === self::None ? 'No priority' : ucfirst(strtolower($this->name));
    }

    public function color(): string
    {
        return match ($this) {
            self::None => '#94a3b8',
            self::Low => '#64748b',
            self::Medium => '#3b82f6',
            self::High => '#f59e0b',
            self::Urgent => '#ef4444',
        };
    }

    /** @return array<int, array{value: int, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'color' => $p->color(),
        ], self::cases());
    }
}
