<?php
/**
 * A sortable table header cell. Variables: $list (ListQuery), $key (sort key), $label.
 * Clicking it sorts by the column; clicking again flips ascending/descending.
 */
?>
<th scope="col" aria-sort="<?= e($list->ariaSort($key)) ?>">
    <a class="sort-link" href="<?= e($list->sortUrl($key)) ?>"><?= e($label) ?><?php
        if ($list->sortKey() === $key): ?> <span aria-hidden="true"><?= $list->direction() === 'ASC' ? '&#9650;' : '&#9660;' ?></span><?php endif; ?></a>
</th>
