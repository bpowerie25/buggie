<?php

namespace App\Enums;

enum IssueType: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Task = 'task';
    case Question = 'question';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
