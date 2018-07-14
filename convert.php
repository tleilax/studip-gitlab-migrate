#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';

$config = require __DIR__ . '/includes/config.php';
$steps  = [
    'issues'   => __DIR__ . '/steps/convert-tickets-to-issues.php',
    'commits'  => __DIR__ . '/steps/clone-svn-repository.php',
    'history'  => __DIR__ . '/steps/rewrite-history.php',
    'push'     => __DIR__ . '/steps/push-repository.php',
    'comments' => __DIR__ . '/steps/add-comments.php',
];

$result = ['issues' => [8641 => 398]];
foreach ($steps as $number => $step) {
    $result[$number] = require $step;
}
