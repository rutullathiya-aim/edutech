<?php

namespace App\Support;

class SpreadsheetCell
{
    /**
     * Characters that trigger formula evaluation in Excel, LibreOffice Calc, or Google Sheets.
     *
     * @var list<string>
     */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * Neutralize potential spreadsheet formula injection by prepending a single quote.
     */
    public static function escapeFormula(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Apply formula-injection escaping across every value in a spreadsheet row.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    public static function escapeRow(array $row): array
    {
        return array_map(
            static fn (mixed $value): mixed => self::escapeFormula($value),
            $row,
        );
    }
}
