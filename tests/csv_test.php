<?php
/**
 * Test script for CsvExporter (admin CSV reports). Run from the project folder:
 *
 *     php tests/csv_test.php
 *
 * Needs no database. Prints PASS/FAIL for each check.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Services\CsvExporter;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

function csv(array $headings, array $rows): string
{
    $stream = fopen('php://memory', 'w+');
    (new CsvExporter())->write($stream, $headings, $rows);
    rewind($stream);
    $text = stream_get_contents($stream);
    fclose($stream);
    return $text;
}

$exporter = new CsvExporter();

echo PHP_EOL, '1. Cell safety', PHP_EOL;
check('formula starting with = gets a leading apostrophe', $exporter->safeCell('=SUM(A1:A9)') === "'=SUM(A1:A9)");
check('+ - @ also get one', $exporter->safeCell('+1') === "'+1" && $exporter->safeCell('-cmd') === "'-cmd" && $exporter->safeCell('@x') === "'@x");
check('normal text is unchanged', $exporter->safeCell('Aga Khan Hospital') === 'Aga Khan Hospital');
check('numbers are unchanged (a negative number is not a formula)', $exporter->safeCell(-5) === '-5' && $exporter->safeCell(12) === '12');
check('null becomes an empty cell', $exporter->safeCell(null) === '');
check('true and false become 1 and 0', $exporter->safeCell(true) === '1' && $exporter->safeCell(false) === '0');
check('empty string stays empty', $exporter->safeCell('') === '');

echo PHP_EOL, '2. File content', PHP_EOL;
$text = csv(['ID', 'Name'], [[1, 'Ali'], [2, 'Sara, "Sam" Khan']]);
check('first line is the headings', strpos($text, "ID,Name\n") === 0);
check('a value with a comma and quotes is quoted correctly', strpos($text, '2,"Sara, ""Sam"" Khan"') !== false);
check('a line break inside a value stays inside its cell',
    strpos(csv(['A'], [["line1\nline2"]]), "\"line1\nline2\"") !== false);
check('formula text in a row is neutralised', strpos(csv(['A'], [['=1+1']]), "'=1+1") !== false);
check('associative rows are written in column order', strpos(csv(['A', 'B'], [['b' => 2, 'a' => 1]]), "2,1") !== false);

$stream = fopen('php://memory', 'w+');
$count = $exporter->write($stream, ['A'], [[1], [2], [3]]);
fclose($stream);
check('write() returns the number of data rows', $count === 3);

$generator = (function () {
    yield [1];
    yield [2];
})();
$stream = fopen('php://memory', 'w+');
check('a generator can be exported (rows are streamed)', $exporter->write($stream, ['A'], $generator) === 2);
fclose($stream);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
