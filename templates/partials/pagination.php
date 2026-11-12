<?php
/**
 * Page links under a list. Variable: $list (a DonorNexa\Services\ListQuery
 * on which applyTotal() has been called).
 */
[$from, $to] = $list->range();
?>
<nav class="pagination" aria-label="Pagination">
    <p class="pagination-summary">
        <?php if ($list->total() === 0): ?>
            No records found.
        <?php else: ?>
            Showing <?= (int) $from ?>&ndash;<?= (int) $to ?> of <?= (int) $list->total() ?>
        <?php endif; ?>
    </p>

    <?php if ($list->totalPages() > 1): ?>
        <ul class="pagination-links">
            <?php if ($list->page() > 1): ?>
                <li><a href="<?= e($list->pageUrl($list->page() - 1)) ?>" rel="prev">&laquo; Previous</a></li>
            <?php endif; ?>

            <?php foreach ($list->pageWindow() as $number): ?>
                <?php if ($number === null): ?>
                    <li class="pagination-gap" aria-hidden="true">&hellip;</li>
                <?php elseif ($number === $list->page()): ?>
                    <li><span class="is-current" aria-current="page"><?= (int) $number ?></span></li>
                <?php else: ?>
                    <li><a href="<?= e($list->pageUrl($number)) ?>"><span class="visually-hidden">Page </span><?= (int) $number ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($list->page() < $list->totalPages()): ?>
                <li><a href="<?= e($list->pageUrl($list->page() + 1)) ?>" rel="next">Next &raquo;</a></li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</nav>
