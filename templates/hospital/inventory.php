<?php
/**
 * Blood inventory of the logged-in hospital.
 * Variables: $stock (group => ['units', 'updated_at']), $total, $old (text the user typed), $errors.
 */

use DonorNexa\Core\Csrf;
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">

<section aria-labelledby="inventory-heading">
    <h1 id="inventory-heading">Blood inventory</h1>
    <p>Units of blood your hospital currently holds, per blood group. Keep it up to date: it helps you decide when to raise a request.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">Please correct the highlighted numbers.</div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/hospital/inventory')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="table-wrap">
            <table class="data-table inventory-table">
                <caption class="visually-hidden">Units in stock for each blood group</caption>
                <thead>
                    <tr>
                        <th scope="col">Blood group</th>
                        <th scope="col">Units in stock</th>
                        <th scope="col">Stock level</th>
                        <th scope="col">Last updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; foreach ($stock as $group => $row): $i++; ?>
                        <?php
                        $value = array_key_exists($group, $old) ? $old[$group] : (string) $row['units'];
                        $error = $errors[$group] ?? null;
                        ?>
                        <tr>
                            <th scope="row"><label for="units-<?= (int) $i ?>"><?= e($group) ?></label></th>
                            <td>
                                <input type="number" id="units-<?= (int) $i ?>" name="units[<?= e($group) ?>]"
                                       value="<?= e($value) ?>" min="0" max="5000" step="1" inputmode="numeric"
                                       required<?= $error !== null ? ' aria-invalid="true" aria-describedby="units-' . (int) $i . '-error"' : '' ?>>
                                <?php if ($error !== null): ?>
                                    <span class="field-error" id="units-<?= (int) $i ?>-error"><?= e($error) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['units'] === 0): ?>
                                    <span class="stock stock-out">Out of stock</span>
                                <?php elseif ($row['units'] <= 2): ?>
                                    <span class="stock stock-low">Low</span>
                                <?php else: ?>
                                    <span class="stock stock-ok">In stock</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $row['updated_at'] !== null ? e($row['updated_at']) : '<span class="muted">Never</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Total</th>
                        <td colspan="3"><strong><?= (int) $total ?></strong> units</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="form-actions">
            <button type="submit" class="btn">Save inventory</button>
            <a class="btn btn-outline" href="<?= e(url('/hospital/requests')) ?>">My requests</a>
        </p>
    </form>
</section>
