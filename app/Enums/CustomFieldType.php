<?php

namespace App\Enums;

/**
 * The kinds of custom field a project can define.
 *
 * Deliberately short. Mantis offers a dozen and most installs use three; every extra
 * type is another validation path, another input, another column in the export and
 * another way for a value to be a string that lies about what it is.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Multiline = 'multiline';
    case Number = 'number';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Url = 'url';
    case Date = 'date';

    /** Only a select has a list to choose from; the rest would ignore one. */
    public function hasOptions(): bool
    {
        return $this === self::Select;
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Multiline => 'Text area',
            self::Number => 'Number',
            self::Select => 'Choice',
            self::Checkbox => 'Checkbox',
            self::Url => 'Link',
            self::Date => 'Date',
        };
    }

    /**
     * The validation a submitted value gets, before the type is trusted anywhere else.
     *
     * Values are stored as text whatever the type, so this is the only thing standing
     * between "number" and a column of prose.
     */
    public function rules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:255'],
            self::Multiline => ['string', 'max:5000'],
            self::Number => ['numeric'],
            self::Select => ['string', 'max:120'],
            self::Checkbox => ['boolean'],
            // A link a member of the team will click. Schemes are restricted for the
            // same reason the branding colour is: javascript: is a valid URL.
            self::Url => ['string', 'max:2048', 'url:http,https'],
            self::Date => ['date'],
        };
    }
}
