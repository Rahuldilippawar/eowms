<?php

namespace App\Support\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Spreadsheet-safe CSV downloads (UTF-8 BOM so Excel shows Marathi, formula cells neutralised). */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prefix cells that a spreadsheet would treat as a formula. */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
