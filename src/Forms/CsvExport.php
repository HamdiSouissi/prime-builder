<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use RuntimeException;

/** Bounded CSV export; never return a silently truncated list of submissions. */
final class CsvExport
{
    public static function render(Submissions $submissions, string $search, ?int $page = null): ?string
    {
        $file = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($file === false) {
            throw new RuntimeException('Unable to create the CSV export.');
        }
        try {
            // A UTF-8 BOM is understood by common spreadsheet applications.
            if (fwrite($file, "\xef\xbb\xbf") !== 3
                || fputcsv($file, ['id', 'entry_id', 'node_id', 'created_at_utc', 'name', 'email', 'message', 'model_id', 'fields_json'], ',', '"', '', "\r\n") === false) {
                throw new RuntimeException('Unable to write the CSV export.');
            }
            $before = null;
            $count = 0;
            do {
                $offset = $page === null ? 0 : ($page - 1) * Submissions::PAGE_SIZE;
                $rows = $submissions->search($search, Submissions::PAGE_SIZE, offset: $offset, beforeId: $before);
                foreach ($rows as $row) {
                    if (++$count > Submissions::EXPORT_MAX_ROWS) {
                        return null;
                    }
                    $cells = [
                        (string) $row['id'], (string) $row['entry_id'], $row['node_id'], $row['created_at'],
                        $row['name'], $row['email'], $row['message'], $row['model_id'] ?? '', $row['fields_json'] ?? '',
                    ];
                    $cells = array_map(self::safeCell(...), $cells);
                    if (fputcsv($file, $cells, ',', '"', '', "\r\n") === false) {
                        throw new RuntimeException('Unable to write the CSV export.');
                    }
                    $size = ftell($file);
                    if ($size === false) {
                        throw new RuntimeException('Unable to measure the CSV export.');
                    }
                    if ($size > Submissions::EXPORT_MAX_BYTES) {
                        return null;
                    }
                    $before = $row['id'];
                }
            } while ($page === null && count($rows) === Submissions::PAGE_SIZE);
            if (!rewind($file)) {
                throw new RuntimeException('Unable to rewind the CSV export.');
            }
            $csv = stream_get_contents($file);
            if ($csv === false) {
                throw new RuntimeException('Unable to read the CSV export.');
            }
            return $csv;
        } finally {
            fclose($file);
        }
    }

    private static function safeCell(string $value): string
    {
        // Quoting alone does not prevent formula execution in a spreadsheet.
        // Also handle leading whitespace, controls and Unicode separators.
        if (preg_match('/^[\x00-\x20]*[=+\-@\x09\x0a\x0d]/', $value) === 1
            || preg_match('/^[\s\p{Z}\p{C}]*[=+\-@]/u', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
