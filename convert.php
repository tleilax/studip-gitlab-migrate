#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/includes/config.php';
$steps  = [
    1 => __DIR__ . '/steps/convert-tickets-to-issues.php',
//    2 => '',
];

$result = [];
foreach ($steps as $number => $step) {
    $result[$number] = require $step;
}
