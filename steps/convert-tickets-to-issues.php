<?php
use Trac2GitLab\Migration;

$issue_mapping = [];

// Actually migrate
$migration = new Migration(
    $config['gitlab-url'],
    $config['gitlab-access-token'],
    $config['gitlab-admin-token'],
    $config['trac-url'],
    $config['create-trac-links'],
    $userMapping = []
);
return $migration->migrateQuery(
    $config['trac-query'],
    $config['gitlab-project']
);
