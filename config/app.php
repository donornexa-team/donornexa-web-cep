<?php
/**
 * Application settings. Returns a plain array; read it with config('key').
 */
return [
    'app_name'     => 'DonorNexa',
    'timezone'     => 'Asia/Karachi',
    'db_time_zone' => '+05:00',   // keeps MySQL NOW() in step with PHP's timezone
    'debug'        => true,       // true while developing: shows error details
    'base_path'    => null,       // auto-detected; set e.g. '/DonorNexa/public' if links break
];
