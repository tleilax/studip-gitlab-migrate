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
$trac = $migration->trac;

$trac_client = $trac->getClient();
$gitlab = $migration->gitLab;
$gitlab_users = $gitlab->listUsers();

$step_size = 50;
$page = 1;
do {
    $query = "{$config['trac-query']}&page={$page}&max={$step_size}";
    try {
        $ticket_ids = $trac_client->execute('ticket.query', [$query]);
    } catch (Exception $e) {
        $ticket_ids = [];
    }

    foreach ($ticket_ids as $ticket_id) {
        try {
            $ticket = $trac_client->execute('ticket.get', [$ticket_id]);

            $title = $ticket[3]['summary'] ?: '¯\_(ツ)_/¯';
            $description = translateTracToMarkdown($ticket[3]['description'], $config['trac-clean-url']);
            $description .= "\n\n---\n\nOriginal ticket: {$config['trac-clean-url']}/ticket/{$ticket_id}";
            $gitLabAssignee = isset($gitlab_users[$ticket[3]['owner']]) ? $gitlab_users[$ticket[3]['owner']] : null;
            $gitLabCreator = isset($gitlab_users[$ticket[3]['reporter']]) ? $gitlab_users[$ticket[3]['reporter']] : null;
            $assigneeId = is_array($gitLabAssignee) ? $gitLabAssignee['id'] : null;
            $creatorId = is_array($gitLabCreator) ? $gitLabCreator['id'] : null;
            $labels = $ticket[3]['keywords'];
            $dateCreated = $ticket[3]['time']['__jsonclass__'][1];
            $dateUpdated = $ticket[3]['_ts'];
            $confidential = (bool) @$ticket[3]['sensitive'];

            $issue = $gitlab->createIssue($config['gitlab-project'], $title,
                $description, $dateCreated, $assigneeId, $creatorId, $labels,
                $confidential);

            echo "Created a GitLab issue #{$issue['iid']} for Trac ticket #{$ticket_id} : {$config['trac-clean-url']}/tickets/{$ticket_id}\n";

            $mapping[$ticket_id] = $issue['iid'];

            $attachments = $trac->getAttachments($ticket_id);

            /*
             * Add files attached to Trac ticket to new Gitlab issue.
             */
            foreach ($attachments as $a) {
                $a['filename'] = str_replace(
                    ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
                    ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
                    $a['filename']
                );

                // TODO: Thomas! WTF! FUCK! MACHEN! SOFORT!

                file_put_contents($a['filename'], base64_decode($a['content']));

                $gitlab->createIssueAttachment($config['gitlab-project'], $issue['iid'], $a['filename'], $a['author']);
                unlink($a['filename']);

                echo "\tAttached file " . $a['filename'] . " to issue " . $issue['iid'] . ".\n";
            }

            // Close issue if Trac ticket was closed.
            if ($ticket[3]['status'] === 'closed') {
                if (isset($ticket[4])) {
                    $gitlab->closeIssue(
                        $config['gitlab-project'], $issue['iid'],
                        $ticket[4][0]['time']['__jsonclass__'][1], $ticket[4][0]['author']
                    );
                } else {
                    $gitlab->closeIssue($config['gitlab-project'], $issue['iid']);
                }
            }
        } catch (Exception $e) {
            throw $e;
            echo "Error creating issue for ticket #{$ticket_id}\n";
        }
    }

    $page += 1;

} while (count($ticket_ids) > 0);

return $migration->migrateQuery(
    $config['trac-query'],
    $config['gitlab-project']
);
