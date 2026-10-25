<?php

namespace App\Support\Export;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Reads an uploaded CSV (UTF-8, optional BOM, comma or semicolon) into rows keyed by the header. */
final class CsvReader
{
    /**
     * @param  list<string>  $required  header columns that must be present
     * @return list<array<string, string>>
     */
    public static function rows(UploadedFile $file, array $required, int $maxRows): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $first = fgets($handle) ?: '';
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($first, $delimiter));
        $missing = array_diff($required, $header);
        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => [__('import.missing_columns', ['columns' => implode(', ', $missing)])]]);
        }

        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null] || implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            if (count($rows) >= $maxRows) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => [__('import.too_many_rows', ['max' => $maxRows])]]);
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => [__('import.empty')]]);
        }

        return $rows;
    }
}
