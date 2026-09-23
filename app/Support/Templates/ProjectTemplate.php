<?php

namespace App\Support\Templates;

use App\Enums\CustomFieldType;
use App\Enums\StatusCategory;

/**
 * One entry from config/templates.php, checked.
 *
 * The config file is editable by anyone self-hosting, so it is treated as input
 * rather than as something known to be correct. Every rule the workflow editor
 * enforces through validation is enforced again here, because a template goes round
 * the editor entirely.
 */
class ProjectTemplate
{
    /**
     * @param  array<int, array{name: string, category: StatusCategory, color: string, is_default: bool, wip_limit: int|null}>  $statuses
     * @param  array<int, array{name: string, color: string, description: string|null}>  $labels
     * @param  array<int, array{name: string, type: CustomFieldType, options: array<int, string>|null, required: bool}>  $fields
     */
    private function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $blurb,
        public readonly array $statuses,
        public readonly array $labels,
        public readonly array $fields,
    ) {}

    /** @throws InvalidTemplate */
    public static function fromConfig(string $key, mixed $raw): self
    {
        if (! is_array($raw)) {
            throw InvalidTemplate::for($key, 'it is not an array.');
        }

        return new self(
            key: $key,
            name: self::text($key, $raw, 'name'),
            blurb: is_string($raw['blurb'] ?? null) ? $raw['blurb'] : '',
            statuses: self::statuses($key, $raw['statuses'] ?? null),
            labels: self::labels($key, $raw['labels'] ?? []),
            fields: self::fields($key, $raw['fields'] ?? []),
        );
    }

    /**
     * @return array<int, array{name: string, category: StatusCategory, color: string, is_default: bool, wip_limit: int|null}>
     *
     * @throws InvalidTemplate
     */
    private static function statuses(string $key, mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw InvalidTemplate::for($key, 'it defines no statuses.');
        }

        $statuses = [];
        $names = [];

        foreach ($raw as $index => $status) {
            if (! is_array($status)) {
                throw InvalidTemplate::for($key, "status #{$index} is not an array.");
            }

            $name = self::text($key, $status, 'name', "status #{$index}");

            if (mb_strlen($name) > 40) {
                throw InvalidTemplate::for($key, "the status name “{$name}” is longer than 40 characters.");
            }

            // The editor refuses two statuses with the same name, so a template must
            // not be able to create the state it refuses to let anybody reach.
            $lowered = mb_strtolower($name);

            if (in_array($lowered, $names, true)) {
                throw InvalidTemplate::for($key, "two statuses are named “{$name}”.");
            }

            $names[] = $lowered;

            $category = StatusCategory::tryFrom((string) ($status['category'] ?? ''));

            if ($category === null) {
                throw InvalidTemplate::for(
                    $key,
                    "the status “{$name}” has no valid category. One of: "
                    .implode(', ', array_column(StatusCategory::cases(), 'value')).'.',
                );
            }

            $statuses[] = [
                'name' => $name,
                'category' => $category,
                'color' => self::color($key, $status['color'] ?? null, "the status “{$name}”"),
                'is_default' => (bool) ($status['is_default'] ?? false),
                'wip_limit' => self::wipLimit($key, $status['wip_limit'] ?? null, $name),
            ];
        }

        self::assertWorkable($key, $statuses);

        return $statuses;
    }

    /**
     * The three things a project's workflow cannot do without.
     *
     * @param  array<int, array{name: string, category: StatusCategory, is_default: bool}>  $statuses
     *
     * @throws InvalidTemplate
     */
    private static function assertWorkable(string $key, array $statuses): void
    {
        $open = array_filter($statuses, fn (array $s) => $s['category']->isOpen());

        if ($open === []) {
            throw InvalidTemplate::for($key, 'no status is in an open category, so a new issue would have nowhere to start.');
        }

        $done = array_filter($statuses, fn (array $s) => $s['category'] === StatusCategory::Done);

        if ($done === []) {
            // Cancelled is closed but was never resolved, so a workflow with only a
            // cancelled status can finish work but can never say it succeeded.
            throw InvalidTemplate::for($key, 'no status is in the done category, so nothing could ever be resolved.');
        }

        $defaults = array_filter($statuses, fn (array $s) => $s['is_default']);

        if (count($defaults) !== 1) {
            throw InvalidTemplate::for(
                $key,
                count($defaults).' statuses are marked as the default; a project needs exactly one.',
            );
        }

        $default = reset($defaults);

        if (! $default['category']->isOpen()) {
            throw InvalidTemplate::for($key, "the default status “{$default['name']}” is closed; new issues cannot start closed.");
        }
    }

    /**
     * @return array<int, array{name: string, color: string, description: string|null}>
     *
     * @throws InvalidTemplate
     */
    private static function labels(string $key, mixed $raw): array
    {
        if (! is_array($raw)) {
            throw InvalidTemplate::for($key, 'its labels are not an array.');
        }

        $labels = [];

        foreach ($raw as $index => $label) {
            if (! is_array($label)) {
                throw InvalidTemplate::for($key, "label #{$index} is not an array.");
            }

            $name = self::text($key, $label, 'name', "label #{$index}");

            if (mb_strlen($name) > 40) {
                throw InvalidTemplate::for($key, "the label name “{$name}” is longer than 40 characters.");
            }

            $description = $label['description'] ?? null;

            $labels[] = [
                'name' => $name,
                'color' => self::color($key, $label['color'] ?? null, "the label “{$name}”"),
                'description' => is_string($description) && $description !== '' ? $description : null,
            ];
        }

        return $labels;
    }

    /**
     * @return array<int, array{name: string, type: CustomFieldType, options: array<int, string>|null, required: bool}>
     *
     * @throws InvalidTemplate
     */
    private static function fields(string $key, mixed $raw): array
    {
        if (! is_array($raw)) {
            throw InvalidTemplate::for($key, 'its fields are not an array.');
        }

        $fields = [];

        foreach ($raw as $index => $field) {
            if (! is_array($field)) {
                throw InvalidTemplate::for($key, "field #{$index} is not an array.");
            }

            $name = self::text($key, $field, 'name', "field #{$index}");

            if (mb_strlen($name) > 60) {
                throw InvalidTemplate::for($key, "the field name “{$name}” is longer than 60 characters.");
            }

            /*
             * Rejected rather than ignored. Silently dropping it would leave whoever
             * edited this file believing a field is shown to clients when it is not,
             * or — far worse if the default ever moved — the reverse.
             */
            if (array_key_exists('visible_to_client', $field)) {
                throw InvalidTemplate::for(
                    $key,
                    "the field “{$name}” sets visible_to_client. Template fields are always internal; "
                    .'share a field from project settings once the project exists.',
                );
            }

            $type = CustomFieldType::tryFrom((string) ($field['type'] ?? ''));

            if ($type === null) {
                throw InvalidTemplate::for(
                    $key,
                    "the field “{$name}” has no valid type. One of: "
                    .implode(', ', array_column(CustomFieldType::cases(), 'value')).'.',
                );
            }

            $options = self::options($key, $field['options'] ?? null, $type, $name);

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'options' => $options,
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $fields;
    }

    /**
     * @return array<int, string>|null
     *
     * @throws InvalidTemplate
     */
    private static function options(string $key, mixed $raw, CustomFieldType $type, string $name): ?array
    {
        if (! $type->hasOptions()) {
            // Options on a text field would be stored, never shown, and confuse
            // whoever read the row next.
            if (! empty($raw)) {
                throw InvalidTemplate::for($key, "the field “{$name}” is a {$type->value} and cannot have options.");
            }

            return null;
        }

        $options = is_array($raw)
            ? array_values(array_unique(array_filter(array_map(
                fn ($option) => is_string($option) ? trim($option) : '',
                $raw,
            ))))
            : [];

        if ($options === []) {
            throw InvalidTemplate::for($key, "the choice field “{$name}” has no choices.");
        }

        return $options;
    }

    /** @throws InvalidTemplate */
    private static function wipLimit(string $key, mixed $raw, string $name): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (! is_int($raw) || $raw < 1) {
            throw InvalidTemplate::for($key, "the status “{$name}” has a wip_limit that is not a positive whole number.");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $source
     *
     * @throws InvalidTemplate
     */
    private static function text(string $key, array $source, string $attribute, ?string $subject = null): string
    {
        $value = $source[$attribute] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidTemplate::for(
                $key,
                $subject ? "{$subject} has no {$attribute}." : "it has no {$attribute}.",
            );
        }

        return trim($value);
    }

    /** @throws InvalidTemplate */
    private static function color(string $key, mixed $raw, string $subject): string
    {
        if (! is_string($raw) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
            throw InvalidTemplate::for($key, "{$subject} has no six-digit hex colour.");
        }

        return strtolower($raw);
    }

    /**
     * What the create screen shows before anybody commits to a choice.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'blurb' => $this->blurb,
            'statuses' => array_map(fn (array $status) => [
                'name' => $status['name'],
                'category' => $status['category']->value,
                'color' => $status['color'],
            ], $this->statuses),
            'labels' => array_column($this->labels, 'name'),
            'fields' => array_column($this->fields, 'name'),
        ];
    }
}
