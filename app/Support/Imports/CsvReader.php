<?php

namespace App\Support\Imports;

use Generator;

/**
 * Reads an exported CSV a row at a time.
 *
 * Streamed rather than loaded: a tracker with ten years of history exports a file
 * that does not want to be an array in memory, and the machine importing it is
 * usually the smallest one somebody could get away with.
 */
class CsvReader
{
    /** Refused above this. An import that takes an hour is one nobody trusts. */
    public const MAX_ROWS = 20_000;

    public function __construct(private string $path) {}

    /** @return array<int, string> */
    public function headers(): array
    {
        $handle = $this->open();
        $headers = fgetcsv($handle) ?: [];
        fclose($handle);

        return array_map(fn ($value) => (string) $value, $headers);
    }

    /**
     * Every row after the header, as field => value.
     *
     * @param  array<string, int>  $mapping
     * @return Generator<int, array<string, string>>
     */
    public function rows(array $mapping): Generator
    {
        $handle = $this->open();

        // Skip the header.
        fgetcsv($handle);

        $number = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $number++;

            if ($number - 1 > self::MAX_ROWS) {
                break;
            }

            // A wholly blank line is the usual end of a spreadsheet export, not a
            // row with nothing in it.
            if ($row === [null] || $row === ['']) {
                continue;
            }

            $values = [];

            foreach ($mapping as $field => $index) {
                $values[$field] = trim((string) ($row[$index] ?? ''));
            }

            yield $number => $values;
        }

        fclose($handle);
    }

    public function count(): int
    {
        $handle = $this->open();
        $rows = 0;

        while (fgetcsv($handle) !== false) {
            $rows++;
        }

        fclose($handle);

        // Less the header.
        return max(0, $rows - 1);
    }

    /** @return resource */
    private function open()
    {
        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Could not read {$this->path}.");
        }

        // Excel writes a byte-order mark and fgetcsv does not strip it, so the first
        // header comes back as "\u{FEFF}Issue key" and matches nothing.
        if (fgets($handle, 4) !== "\u{FEFF}") {
            rewind($handle);
        }

        return $handle;
    }
}
