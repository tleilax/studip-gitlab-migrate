<?php
use Trac2GitLab\Migration;

$issue_mapping = [];

// Actually migrate
$migration = new Migration(
    $config['gitlab-api-url'],
    $config['gitlab-access-token'],
    $config['gitlab-admin-token'],
    $config['trac-url'],
    $config['create-trac-links'],
    $userMapping = []
);
$trac = $migration->trac->getClient();

$step_size = 50;
$page = 1;
do {
    $query = "{$config['trac-query']}&page={$page}&max={$step_size}";
    try {
        $ticket_ids = $trac->execute('ticket.query', [$query]);
        $migration->migrate($ticket_ids, $config['gitlab-project']);
    } catch (Exception $e) {
        $ticket_ids = [];
    }

    $page += 1;
} while (count($ticket_ids) > 0);

return $migration->migrateQuery(
    $config['trac-query'],
    $config['gitlab-project']
);
