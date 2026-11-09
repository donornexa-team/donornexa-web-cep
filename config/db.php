<?php
/**
 * (ignored by Git). Copy config/db.example.php to create it.
 */
$local = __DIR__ . '/db.local.php';

if (!is_file($local)) {
    throw new RuntimeException(
        'Missing config/db.local.php. Copy config/db.example.php to config/db.local.php.'
    );
}

return require $local;
