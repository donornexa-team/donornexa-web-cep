<?php
/**
 * Live donor search (admin only). Variables: $cities, $bloodGroups, $apiUrl.
 * The table is filled by public/assets/js/donor_search.js from api/donors-search.php.
 * No phone numbers, CNICs or emails are shown here.
 */
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">

<section aria-labelledby="donors-heading" id="donor-search" data-api-url="<?= e($apiUrl) ?>">
    <h1 id="donors-heading">Donor search</h1>
    <p>Type a name or pick a filter: the list updates as you go. Contact details are never shown here.</p>

    <form class="list-filters" id="donor-filter" role="search" action="<?= e(url('/admin/donors')) ?>" method="get">
        <div class="filter-field">
            <label for="ds-q">Name</label>
            <input type="search" id="ds-q" name="q" maxlength="100" autocomplete="off" placeholder="Start typing a name">
        </div>

        <div class="filter-field">
            <label for="ds-group">Blood group</label>
            <select id="ds-group" name="blood_group">
                <option value="">All</option>
                <?php foreach ($bloodGroups as $group): ?>
                    <option value="<?= e($group) ?>"><?= e($group) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="ds-city">City</label>
            <select id="ds-city" name="city_id">
                <option value="">All</option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= (int) $city['id'] ?>"><?= e($city['city_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="ds-sort">Sort by</label>
            <select id="ds-sort" name="sort">
                <option value="name">Name</option>
                <option value="blood">Blood group</option>
                <option value="city">City</option>
                <option value="last">Last donation</option>
            </select>
        </div>

        <div class="filter-field">
            <label for="ds-dir">Order</label>
            <select id="ds-dir" name="dir">
                <option value="asc">A to Z / oldest first</option>
                <option value="desc">Z to A / newest first</option>
            </select>
        </div>

        <div class="filter-field filter-check">
            <label><input type="checkbox" id="ds-eligible" name="eligible" value="1"> Eligible to donate now</label>
        </div>

        <noscript><div class="filter-actions"><button type="submit" class="btn btn-small">Search</button></div></noscript>
    </form>

    <p id="ds-status" class="pagination-summary" role="status" aria-live="polite">Loading donors&hellip;</p>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Donors matching the search</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Blood group</th>
                    <th scope="col">City</th>
                    <th scope="col">Account</th>
                    <th scope="col">Available</th>
                    <th scope="col">Last donation</th>
                    <th scope="col">Eligible now</th>
                </tr>
            </thead>
            <tbody id="ds-rows"></tbody>
        </table>
    </div>

    <nav class="pagination" aria-label="Donor search pages">
        <button type="button" class="btn btn-small btn-outline" id="ds-prev" disabled>&laquo; Previous</button>
        <span id="ds-page" class="pagination-summary"></span>
        <button type="button" class="btn btn-small btn-outline" id="ds-next" disabled>Next &raquo;</button>
    </nav>
</section>

<script src="<?= e(asset('js/donor_search.js')) ?>" defer></script>
