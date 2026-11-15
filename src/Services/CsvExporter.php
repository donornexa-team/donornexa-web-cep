<?php
declare(strict_types=1);

namespace DonorNexa\Services;

/**
 * Writes rows as a CSV file (admin reports).
 *
 * Two safety measures:
 *  - fputcsv() does the quoting, so commas, quotes and line breaks inside a
 *    value cannot break the columns;
 *  - "CSV injection": a text cell that starts with = + - or @ would be run as a
 *    formula when the file is opened in Excel, so such cells get a leading '
 *    (see safeCell()). Numbers are left alone.
 *
 * A UTF-8 byte-order mark is written first so Excel shows Urdu and other
 * non-English names correctly.
 */
class CsvExporter
{
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Download: sends the headers and the whole file to the browser.
     *
     * @param string     $filename  file name proposed to the browser, e.g. 'donornexa_audit.csv'
     * @param string[]   $headings  first row
     * @param iterable   $rows      each row is an array of values
     * @return int number of data rows written
     */
    public function send(string $filename, array $headings, iterable $rows): int
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $count = $this->write($out, $headings, $rows);
        fclose($out);

        return $count;
    }

    /**
     * Write the CSV to any open stream (used by send() and by the tests).
     *
     * @param resource $handle
     * @return int number of data rows written
     */
    public function write($handle, array $headings, iterable $rows): int
    {
        $this->putRow($handle, $headings);

        $count = 0;
        foreach ($rows as $row) {
            $this->putRow($handle, array_values($row));
            $count++;
        }

        return $count;
    }

    /** One cell as safe text: null becomes '', formula-looking text gets a leading '. */
    public function safeCell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!is_string($value)) {
            return (string) $value;
        }
        if ($value !== '' && in_array($value[0], self::FORMULA_STARTS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function putRow($handle, array $cells): void
    {
        fputcsv($handle, array_map([$this, 'safeCell'], $cells), ',', '"', '\\');
    }
}
