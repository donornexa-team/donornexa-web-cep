<?php
/**
 * Test script for the ListQuery helper (search, sort, filter, pagination).
 * Run from the project folder:
 *
 *     php tests/listquery_test.php
 *
 * Needs no database. Prints PASS/FAIL for each check.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Services\ListQuery;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

$sortable = ['when' => 'a.created_at', 'user' => 'u.name', 'action' => 'a.action'];

function make(array $get, int $perPage = 10): ListQuery
{
    global $sortable;
    return new ListQuery($get, '/admin/audit', $sortable, 'when', 'desc', $perPage);
}

echo PHP_EOL, '1. Sorting (whitelist)', PHP_EOL;
check('no parameters: default sort, newest first', make([])->orderBy() === 'a.created_at DESC');
check('sort=user&dir=asc is accepted', make(['sort' => 'user', 'dir' => 'asc'])->orderBy() === 'u.name ASC');
check('unknown sort key falls back to the default',
    make(['sort' => 'password_hash'])->orderBy() === 'a.created_at DESC');
check('SQL injection in sort never reaches ORDER BY',
    make(['sort' => 'when; DROP TABLE users', 'dir' => 'asc; --'])->orderBy() === 'a.created_at DESC');
check('direction other than asc/desc falls back to the default', make(['dir' => 'sideways'])->direction() === 'DESC');
check('sort sent as an array is ignored', make(['sort' => ['user']])->sortKey() === 'when');

echo PHP_EOL, '2. Search text', PHP_EOL;
check('search is trimmed', make(['q' => '  login  '])->search() === 'login');
check('search is cut to 100 characters', strlen(make(['q' => str_repeat('a', 500)])->search()) === 100);
check('% and _ in the search are escaped for LIKE', make(['q' => '50%_off'])->likeTerm() === '%50\\%\\_off%');
check('search sent as an array becomes empty', make(['q' => ['x']])->search() === '');

echo PHP_EOL, '3. Filters', PHP_EOL;
check('a listed filter value is accepted', make(['entity' => 'users'])->filter('entity', ['users', 'donations']) === 'users');
check('a value not in the list is dropped', make(['entity' => 'x'])->filter('entity', ['users']) === '');

echo PHP_EOL, '4. Pagination', PHP_EOL;
$list = make(['page' => '2']);
$list->applyTotal(35);
check('35 rows at 10 per page = 4 pages', $list->totalPages() === 4);
check('page 2 starts at offset 10', $list->offset() === 10);
check('range of page 2 is 11-20', $list->range() === [11, 20]);

$list = make(['page' => '99']);
$list->applyTotal(35);
check('a page number that is too high is moved to the last page', $list->page() === 4);
check('the last page shows rows 31-35', $list->range() === [31, 35]);

check('page=0 becomes 1', make(['page' => '0'])->page() === 1);
check('page=-3 becomes 1', make(['page' => '-3'])->page() === 1);
check('page=abc becomes 1', make(['page' => 'abc'])->page() === 1);
check('page sent as an array becomes 1', make(['page' => ['5']])->page() === 1);

$list = make([]);
$list->applyTotal(0);
check('no rows: one page, range 0-0', $list->totalPages() === 1 && $list->range() === [0, 0]);

$list = make(['page' => '10']);
$list->applyTotal(200);
check('page window shows first, last and 2 around the current page',
    $list->pageWindow() === [1, null, 8, 9, 10, 11, 12, null, 20]);

echo PHP_EOL, '5. Links', PHP_EOL;
$list = make(['q' => 'login', 'sort' => 'user', 'dir' => 'asc', 'page' => '2']);
$list->filter('entity', ['users']);
$list->applyTotal(100);
check('page links keep the search and sorting',
    strpos($list->pageUrl(3), 'q=login') !== false && strpos($list->pageUrl(3), 'sort=user') !== false
    && strpos($list->pageUrl(3), 'page=3') !== false);
check('page 1 link has no page parameter', strpos($list->pageUrl(1), 'page=') === false);
check('clicking the sorted column flips the direction', strpos($list->sortUrl('user'), 'dir=desc') !== false);
check('clicking another column sorts ascending from page 1',
    strpos($list->sortUrl('action'), 'dir=asc') !== false && strpos($list->sortUrl('action'), 'page=') === false);
check('aria-sort says ascending for the sorted column and none for others',
    $list->ariaSort('user') === 'ascending' && $list->ariaSort('action') === 'none');
check('search text in links is URL-encoded',
    strpos(make(['q' => 'a&b=c'])->url(), 'q=a%26b%3Dc') !== false);

$qs = $list->queryString();
check('queryString keeps search, sorting and filters but not the page',
    strpos($qs, 'q=login') !== false && strpos($qs, 'sort=user') !== false
    && strpos($qs, 'entity=users') === false /* filter was not set by the URL */ && strpos($qs, 'page') === false);
check('queryString of an empty list is empty or only the default sort',
    strpos(make([])->queryString(), 'q=') === false);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
