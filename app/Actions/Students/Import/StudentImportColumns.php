<?php

namespace App\Actions\Students\Import;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\SocialCategory;
use App\Models\Grade;
use App\Models\Student;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class StudentImportColumns
{
    /**
     * Maximum number of student data rows allowed in a single import upload.
     */
    public const int MAX_ROWS = 1000;

    /**
     * Maximum number of physical rows scanned (including blank rows) to guard against sparse-sheet DoS.
     */
    public const int MAX_PHYSICAL_ROWS = 5000;

    /**
     * Maximum upload file size in bytes (5 MB).
     */
    public const int MAX_FILE_BYTES = 5 * 1024 * 1024;

    /**
     * Allowed upload file extensions.
     *
     * @var list<string>
     */
    public const array ALLOWED_EXTENSIONS = ['xlsx', 'csv'];

    /**
     * Ordered list of canonical student import columns with whether each header carries an asterisk.
     *
     * @var array<string, bool>
     */
    public const array DEFINITIONS = [
        'admission_no' => true,
        'admission_date' => true,
        'name' => true,
        'date_of_birth' => true,
        'gender' => true,
        'grade' => true,
        'section' => true,
        'roll_number' => false,
        'father_name' => false,
        'father_mobile' => false,
        'mother_name' => false,
        'mother_mobile' => false,
        'guardian_name' => false,
        'guardian_mobile' => false,
        'guardian_relationship' => false,
        'primary_contact' => true,
        'address_line' => false,
        'city' => false,
        'state' => false,
        'pincode' => false,
        'blood_group' => false,
        'mother_tongue' => false,
        'previous_school' => false,
        'social_category' => false,
        'religion' => false,
        'apaar_id' => false,
        'pen' => false,
        'aadhaar_last4' => false,
    ];

    /**
     * Columns that must exist in an uploaded import file's header row.
     *
     * @var list<string>
     */
    public const array REQUIRED_HEADERS = [
        'admission_no',
        'admission_date',
        'name',
        'date_of_birth',
        'gender',
        'grade',
        'section',
        'primary_contact',
    ];

    /**
     * Roman numeral lookup for grades 1 to 12.
     *
     * @var array<int, string>
     */
    private const array ROMAN_NUMERALS = [
        1 => 'i',
        2 => 'ii',
        3 => 'iii',
        4 => 'iv',
        5 => 'v',
        6 => 'vi',
        7 => 'vii',
        8 => 'viii',
        9 => 'ix',
        10 => 'x',
        11 => 'xi',
        12 => 'xii',
    ];

    /**
     * Return ordered column keys filtered by whether the actor can access sensitive student fields.
     *
     * @return list<string>
     */
    public static function allowedColumns(bool $includeSensitive): array
    {
        $columns = [];

        foreach (array_keys(self::DEFINITIONS) as $column) {
            if (! $includeSensitive && in_array($column, Student::VIEW_SENSITIVE_FIELDS, true)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Return ordered spreadsheet headers (with trailing asterisks on required columns).
     *
     * @return list<string>
     */
    public static function templateHeaders(bool $includeSensitive): array
    {
        return array_map(
            static fn (string $column): string => self::DEFINITIONS[$column] ? "{$column}*" : $column,
            self::allowedColumns($includeSensitive),
        );
    }

    /**
     * Normalize a raw spreadsheet header string to its canonical column key, or null if unknown/ignored.
     */
    public static function normalizeHeader(mixed $rawHeader): ?string
    {
        if (! is_string($rawHeader) && ! is_int($rawHeader) && ! is_float($rawHeader)) {
            return null;
        }

        $trimmed = trim((string) $rawHeader);
        $stripped = trim(rtrim($trimmed, '*'));
        $normalized = Str::lower((string) preg_replace('/\s+/', '_', $stripped));

        return array_key_exists($normalized, self::DEFINITIONS) ? $normalized : null;
    }

    /**
     * Normalize a cell value into a trimmed nullable string.
     */
    public static function normalizeCellString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Parse an uploaded spreadsheet date cell (DateTimeInterface, Excel serial, dd/mm/yyyy, dd-mm-yyyy, or dd.mm.yyyy).
     */
    public static function parseImportDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_int($value) || is_float($value)) {
            return self::parseExcelSerialDate((float) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed) && strpbrk($trimmed, '/-.') === false) {
            return self::parseExcelSerialDate((float) $trimmed);
        }

        if (preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})\2(\d{4})$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
        $candidate = "{$day}-{$month}-{$matches[4]}";

        try {
            $parsed = CarbonImmutable::createFromFormat('!d-m-Y', $candidate);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('d-m-Y') !== $candidate) {
            return null;
        }

        return $parsed->startOfDay();
    }

    /**
     * Classify a non-empty date cell as either a native/numeric Excel date ('real') or a formatted string ('text').
     */
    public static function classifyDateCellKind(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface || is_int($value) || is_float($value)) {
            return 'real';
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed) && strpbrk($trimmed, '/-.') === false) {
            return 'real';
        }

        if (preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})\2(\d{4})$/', $trimmed) === 1) {
            return 'text';
        }

        return null;
    }

    /**
     * Get the private temporary directory used for generated import spreadsheets.
     */
    public static function tempDirectory(): string
    {
        return storage_path('app/private/tmp');
    }

    /**
     * Allocate a unique .xlsx temporary file path inside storage/app/private/tmp.
     */
    public static function allocateTempXlsxPath(string $prefix): string
    {
        $directory = self::tempDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR."{$prefix}-".Str::ulid().'.xlsx';
    }

    /**
     * Delete leftover temporary spreadsheets inside storage/app/private/tmp older than the given age in seconds.
     */
    public static function pruneExpiredTempFiles(int $maxAgeSeconds = 3600): int
    {
        $directory = self::tempDirectory();

        if (! is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);

            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Parse a spreadsheet gender cell case-insensitively.
     */
    public static function parseGender(?string $value): ?Gender
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        foreach (Gender::cases() as $case) {
            if ($normalized === $case->value || $normalized === Str::lower($case->label())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Parse a spreadsheet social category cell case-insensitively.
     */
    public static function parseSocialCategory(?string $value): ?SocialCategory
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        foreach (SocialCategory::cases() as $case) {
            if ($normalized === $case->value || $normalized === Str::lower($case->label())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Parse a spreadsheet blood group cell case-insensitively.
     */
    public static function parseBloodGroup(?string $value): ?BloodGroup
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::upper((string) preg_replace('/\s+/', '', trim($value)));

        return BloodGroup::tryFrom($normalized);
    }

    /**
     * Parse a spreadsheet Indian state/UT cell case-insensitively.
     */
    public static function parseIndianState(?string $value): ?IndianState
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::collapseKey($value);

        foreach (IndianState::cases() as $case) {
            if ($normalized === self::collapseKey($case->value)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Parse a spreadsheet guardian relationship cell case-insensitively.
     */
    public static function parseGuardianRelationship(?string $value): ?GuardianRelationship
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        foreach (GuardianRelationship::cases() as $case) {
            if ($normalized === $case->value || $normalized === Str::lower($case->label())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Build lookup maps of exact grade names, exact short names, and common Indian aliases to Grade records.
     *
     * @param  Collection<int, Grade>  $grades
     * @return array{
     *     exact_names: array<string, list<Grade>>,
     *     exact_shorts: array<string, list<Grade>>,
     *     aliases: array<string, list<Grade>>
     * }
     */
    public static function buildGradeLookup(Collection $grades): array
    {
        $exactNames = [];
        $exactShorts = [];
        $aliases = [];

        foreach ($grades as $grade) {
            $exactNameKey = self::collapseKey($grade->name);

            if ($exactNameKey !== '') {
                $exactNames[$exactNameKey] = self::appendUniqueGrade($exactNames[$exactNameKey] ?? [], $grade);
                $aliases[$exactNameKey] = self::appendUniqueGrade($aliases[$exactNameKey] ?? [], $grade);
            }

            $shortName = is_string($grade->short_name) ? self::collapseKey($grade->short_name) : '';

            if ($shortName !== '') {
                $exactShorts[$shortName] = self::appendUniqueGrade($exactShorts[$shortName] ?? [], $grade);
                $aliases[$shortName] = self::appendUniqueGrade($aliases[$shortName] ?? [], $grade);
            }

            foreach (self::extractNumericGradeAliases($grade->name, $grade->short_name) as $aliasKey) {
                $aliases[$aliasKey] = self::appendUniqueGrade($aliases[$aliasKey] ?? [], $grade);
            }
        }

        return [
            'exact_names' => $exactNames,
            'exact_shorts' => $exactShorts,
            'aliases' => $aliases,
        ];
    }

    /**
     * Normalize a lookup string key (trimmed, lowercase, single-spaced, punctuation normalized).
     */
    public static function collapseKey(string $value): string
    {
        $cleaned = str_replace('.', '', trim($value));

        return Str::lower((string) preg_replace('/\s+/', ' ', $cleaned));
    }

    /**
     * Convert an Excel serial number (1900 date system) into a CarbonImmutable date.
     */
    private static function parseExcelSerialDate(float $serial): ?CarbonImmutable
    {
        if (! is_finite($serial) || $serial < 1 || $serial > 2958465) {
            return null;
        }

        $wholeDays = (int) floor($serial);

        if ($wholeDays === 60) {
            return null;
        }

        $epoch = CarbonImmutable::create(1899, 12, 30, 0, 0, 0);

        if (! $epoch instanceof CarbonImmutable) {
            return null;
        }

        $offsetDays = $wholeDays < 60 ? $wholeDays + 1 : $wholeDays;

        return $epoch->addDays($offsetDays)->startOfDay();
    }

    /**
     * Generate Indian class aliases (`Class 5`, `5`, `V`, `Std 5`, `5th`, etc.) when a grade maps to 1..12.
     *
     * @return list<string>
     */
    private static function extractNumericGradeAliases(string $name, ?string $shortName): array
    {
        $number = self::detectClassNumber($name) ?? ($shortName !== null ? self::detectClassNumber($shortName) : null);

        if ($number === null || ! isset(self::ROMAN_NUMERALS[$number])) {
            return [];
        }

        $roman = self::ROMAN_NUMERALS[$number];
        $ordinal = match ($number) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => "{$number}th",
        };

        return [
            (string) $number,
            $roman,
            $ordinal,
            "class {$number}",
            "class {$roman}",
            "class {$ordinal}",
            "std {$number}",
            "std {$roman}",
            "std {$ordinal}",
            "standard {$number}",
            "standard {$roman}",
            "grade {$number}",
            "grade {$roman}",
            "grade {$ordinal}",
        ];
    }

    /**
     * Extract numeric class level 1..12 from a grade name or short name.
     */
    private static function detectClassNumber(string $raw): ?int
    {
        $normalized = self::collapseKey($raw);

        if (preg_match('/^(?:class|grade|std|standard)?\s*(\d{1,2})(?:st|nd|rd|th)?$/', $normalized, $matches) === 1) {
            $value = (int) $matches[1];

            return ($value >= 1 && $value <= 12) ? $value : null;
        }

        if (preg_match('/^(?:class|grade|std|standard)?\s*(i|ii|iii|iv|v|vi|vii|viii|ix|x|xi|xii)$/', $normalized, $matches) === 1) {
            $found = array_search($matches[1], self::ROMAN_NUMERALS, true);

            return is_int($found) ? $found : null;
        }

        return null;
    }

    /**
     * Append a grade to a candidate list once by ULID.
     *
     * @param  list<Grade>  $list
     * @return list<Grade>
     */
    private static function appendUniqueGrade(array $list, Grade $grade): array
    {
        foreach ($list as $existing) {
            if ($existing->id === $grade->id) {
                return $list;
            }
        }

        $list[] = $grade;

        return $list;
    }
}
