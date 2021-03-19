#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';

Trac2GitLab\Cache::setPath(__DIR__ . '/cache');
if ($_SERVER['argc'] > 1 && $_SERVER['argv'][1] === '--clear-cache') {
    Trac2GitLab\Cache::getInstance()->clear();
}

$config = require __DIR__ . '/includes/config.php';
$config['trac-clean-url'] = preg_replace('/(?<=:\/\/).*?:.*?@|\/login/', '${1}', $config['trac-url']);

$steps  = [
    'issues'   => __DIR__ . '/steps/convert-tickets-to-issues.php',
    'commits'  => __DIR__ . '/steps/clone-svn-repository.php',
    'history'  => __DIR__ . '/steps/rewrite-history.php',
    'push'     => __DIR__ . '/steps/push-repository.php',
//    'comments' => __DIR__ . '/steps/add-comments.php',
];

if (!class_exists('Transliterator')) {
    die("PHP extension intl with Transliterator class is needed.\n");
}

$result = [];
foreach ($steps as $number => $step) {
    $result[$number] = require $step;
}
