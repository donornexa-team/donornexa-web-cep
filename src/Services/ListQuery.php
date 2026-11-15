<?php
declare(strict_types=1);

namespace DonorNexa\Services;

/**
 * Reusable helper for every list page (audit log, and later users, hospitals,
 * requests, donors): search, filters, sorting and pagination from the URL.
 *
 *   $list = new ListQuery($_GET, '/admin/audit', ['when' => 'a.created_at', 'user' => 'u.name'], 'when');
 *   $entity = $list->filter('entity', $allowedEntities);
 *   $list->applyTotal($repository->count($list->search(), $entity));
 *   ...SQL...  ORDER BY {$list->orderBy()}  LIMIT {$list->perPage()} OFFSET {$list->offset()}
 *
 * Security: the sort column is NEVER taken from the URL directly. The URL only
 * carries a key ("when"), which is looked up in the $sortable whitelist, so
 * nothing the visitor types can reach the ORDER BY clause. Search text is
 * passed to SQL only as a prepared-statement parameter (see likeTerm()).
 *
 * It does not touch the database or the superglobals, so it can be tested
 * from the command line (tests/listquery_test.php).
 */
class ListQuery
{
    public const MAX_SEARCH_LENGTH = 100;

    private string $path;
    private array $sortable;
    private string $sortKey;
    private string $direction;
    private int $perPage;
    private string $search;
    private int $page;
    private int $total = 0;
    private array $filters = [];
    /** @var array the original query string, kept for filter() */
    private array $get;

    /**
     * @param array  $get         the query string (normally $_GET)
     * @param string $path        page path used when building links, e.g. '/admin/audit'
     * @param array  $sortable    sort key => real SQL column, e.g. ['when' => 'a.created_at']
     * @param string $defaultSort a key of $sortable
     * @param string $defaultDir  'asc' or 'desc'
     * @param int    $perPage     rows per page
     */
    public function __construct(
        array $get,
        string $path,
        array $sortable,
        string $defaultSort,
        string $defaultDir = 'desc',
        int $perPage = 15
    ) {
        $this->path     = $path;
        $this->sortable = $sortable;
        $this->perPage  = max(1, $perPage);

        $sort = $this->text($get, 'sort');
        $this->sortKey = isset($sortable[$sort]) ? $sort : $defaultSort;

        $dir = strtolower($this->text($get, 'dir'));
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = strtolower($defaultDir) === 'asc' ? 'asc' : 'desc';
        }
        $this->direction = $dir;

        $this->search = mb_substr($this->text($get, 'q'), 0, self::MAX_SEARCH_LENGTH);

        $page = $this->text($get, 'page');
        $this->page = (ctype_digit($page) && (int) $page >= 1) ? (int) $page : 1;

        $this->get = $get;
    }

    // ------------------------------------------------------------ the inputs

    public function search(): string
    {
        return $this->search;
    }

    /**
     * A filter such as ?entity=users. Only a value from $allowed is accepted,
     * anything else becomes '' (no filter). Remembered for link building.
     */
    public function filter(string $name, array $allowed): string
    {
        $value = $this->text($this->get, $name);
        $value = in_array($value, $allowed, true) ? $value : '';
        $this->filters[$name] = $value;

        return $value;
    }

    /** Search text as a LIKE pattern: %text% with % and _ escaped. */
    public function likeTerm(): string
    {
        return '%' . addcslashes($this->search, '%_\\') . '%';
    }

    // --------------------------------------------------------------- sorting

    public function sortKey(): string
    {
        return $this->sortKey;
    }

    /** 'ASC' or 'DESC' (safe to put into SQL). */
    public function direction(): string
    {
        return strtoupper($this->direction);
    }

    /** Safe ORDER BY content, e.g. "a.created_at DESC". */
    public function orderBy(): string
    {
        return $this->sortable[$this->sortKey] . ' ' . $this->direction();
    }

    /** Value for the aria-sort attribute of a column header. */
    public function ariaSort(string $key): string
    {
        if ($key !== $this->sortKey) {
            return 'none';
        }
        return $this->direction === 'asc' ? 'ascending' : 'descending';
    }

    /** Link of a column header: sorts by the column, or flips the direction if it is already sorted. */
    public function sortUrl(string $key): string
    {
        $dir = ($key === $this->sortKey && $this->direction === 'asc') ? 'desc' : 'asc';

        return $this->url(['sort' => $key, 'dir' => $dir, 'page' => 1]);
    }

    // ------------------------------------------------------------ pagination

    public function perPage(): int
    {
        return $this->perPage;
    }

    /** Tell the helper how many rows match; it keeps the page number inside the valid range. */
    public function applyTotal(int $total): void
    {
        $this->total = max(0, $total);
        $this->page  = min($this->page, $this->totalPages());
    }

    public function total(): int
    {
        return $this->total;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Position of the first and last row on this page, for "Showing 16-30 of 52". */
    public function range(): array
    {
        if ($this->total === 0) {
            return [0, 0];
        }
        return [$this->offset() + 1, min($this->offset() + $this->perPage, $this->total)];
    }

    /**
     * Page numbers to show: always the first and last, and two either side of
     * the current page; null marks a gap ("...").
     *
     * @return array<int|null>
     */
    public function pageWindow(): array
    {
        $last  = $this->totalPages();
        $pages = [];
        for ($p = 1; $p <= $last; $p++) {
            if ($p === 1 || $p === $last || abs($p - $this->page) <= 2) {
                $pages[] = $p;
            }
        }

        $result = [];
        $previous = 0;
        foreach ($pages as $p) {
            if ($previous !== 0 && $p - $previous > 1) {
                $result[] = null;
            }
            $result[] = $p;
            $previous = $p;
        }

        return $result;
    }

    // ----------------------------------------------------------------- links

    /** Link to this list with the current search/sort/filters and some values changed. */
    public function url(array $changes = []): string
    {
        $params = array_merge(
            ['q' => $this->search, 'sort' => $this->sortKey, 'dir' => $this->direction, 'page' => $this->page],
            $this->filters,
            $changes
        );

        // Leave out empty values and page 1 to keep links short.
        $params = array_filter($params, static function ($value, $key): bool {
            return $value !== '' && !($key === 'page' && (int) $value === 1);
        }, ARRAY_FILTER_USE_BOTH);

        $query = http_build_query($params);

        return url($this->path) . ($query !== '' ? '?' . $query : '');
    }

    /**
     * The current search, sort and filters as a query string WITHOUT the page
     * number and without a leading "?" (for links such as the CSV export,
     * which must contain every matching row, not just one page).
     */
    public function queryString(): string
    {
        $params = array_merge(
            ['q' => $this->search, 'sort' => $this->sortKey, 'dir' => $this->direction],
            $this->filters
        );

        return http_build_query(array_filter($params, static function ($value): bool {
            return $value !== '';
        }));
    }

    /** Link to another page number. */
    public function pageUrl(int $page): string
    {
        return $this->url(['page' => $page]);
    }

    /** A value of the query string as trimmed text (arrays become ''). */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
