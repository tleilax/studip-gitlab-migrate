<?php
use Trac2GitLab\Migration;

$cache = Trac2GitLab\Cache::getInstance();
// Maps svn tickets to gitlab issues
$issue_mapping = $cache->get('issue-mapping', []);
// Trac Milestones that have already been created in Gitlab.
$milestones = $cache->get('milestones', []);
// Milestones that have already been migrated and closed.
$closedMilestones = $cache->get('closed-milestones', []);

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
$gitlab_users = []; // $gitlab->listUsers();

$step_size = 50;
$page = 1;

stream_set_blocking(STDIN, 0);

do {
    $query = "{$config['trac-query']}&page={$page}&max={$step_size}";
    try {
        $ticket_ids = $trac_client->execute('ticket.query', [$query]);
    } catch (Exception $e) {
        $ticket_ids = [];
    }


    foreach ($ticket_ids as $ticket_id) {
        while ($c = fgetc(STDIN)) {
            if ($c === 'q') {
                echo "! Stopping process\n";
                exit;
            }
        }
        if (isset($issue_mapping[$ticket_id])) {
            continue;
        }

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

            // Check if milestone must be created.
            if (is_array($ticket[3]) && isset($ticket[3]['milestone']) && $ticket[3]['milestone'] !== '') {
                /*
                 * Create a new milestone in Gitlab and use its ID it if
                 * it doesn't exist in Gitlab yet.
                 */
                if (!isset($milestones[$ticket[3]['milestone']]) || !is_array($milestones[$ticket[3]['milestone']])) {
                    $m = $trac->getMilestone($ticket[3]['milestone']);
                    $g = $gitlab->createMilestone($config['gitlab-project'], $m['name'],
                        translateTracToMarkdown($m['description'], $trac->getUrl()),
                        is_array($m['due']) ? $m['due']['__jsonclass__'][1] : '', '');

                    $milestones[$ticket[3]['milestone']] = [
                        'id' => $g['id'],
                        'closed' => is_array($m['completed'])
                    ];
                    $cache->set('milestones', $milestones);
                    echo "Created milestone " . $ticket[3]['milestone'] . ".\n";
                }
            }

            $milestone = is_array($ticket[3]) &&
                    $ticket[3]['milestone'] !== '' &&
                    $milestones[$ticket[3]['milestone']] ?
                $milestones[$ticket[3]['milestone']]['id'] : 0;

                $issue = $gitlab->createIssue($config['gitlab-project'], $title,
                $description, $dateCreated, $assigneeId, $creatorId, $labels,
                $confidential, $milestone);

            echo "Created a GitLab issue #{$issue['iid']} for Trac ticket #{$ticket_id} : {$config['trac-clean-url']}/ticket/{$ticket_id}\n";

            $issue_mapping[$ticket_id] = $issue['iid'];
            $cache->set('issue-mapping', $issue_mapping);

            $attachments = $trac->getAttachments($ticket_id);

            /*
             * Create a transliterator for treating file names with special
             * characters in them.
             */
            $trans = \Transliterator::create('Latin-ASCII');

            /*
             * Add files attached to Trac ticket to new Gitlab issue.
             */
            foreach ($attachments as $a) {
                // Transliterate file name, using only "safe" characters.
                $filename = $trans->transliterate($a['filename']);

                file_put_contents($filename, base64_decode($a['content']));

//                $gitlab->createIssueAttachment($config['gitlab-project'], $issue['iid'], $filename, $a['author']);
                $gitlab->createIssueAttachment($config['gitlab-project'], $issue['iid'], $filename, null);
                unlink($filename);

                echo "\tAttached file {$filename} to issue {$issue['iid']}\n";
            }

            // Close issue if Trac ticket was closed.
            if ($ticket[3]['status'] === 'closed') {
                if (isset($ticket[4])) {
                    $gitlab->closeIssue(
                        $config['gitlab-project'], $issue['iid'],
                        $ticket[4][0]['time']['__jsonclass__'][1]
//                        $ticket[4][0]['time']['__jsonclass__'][1], $ticket[4][0]['author']
                    );
                } else {
                    $gitlab->closeIssue($config['gitlab-project'], $issue['iid']);
                }
            }

            // Close milestone if necessary.
            if (is_array($ticket[3]) && $ticket[3]['milestone'] !== '' &&
                !in_array($milestones[$ticket[3]['milestone']]['id'], $closedMilestones)
            ) {
                $gitlab->closeMilestone($config['gitlab-project'], $milestones[$ticket[3]['milestone']]['id']);
                $closedMilestones[] = $milestones[$ticket[3]['milestone']]['id'];
                $cache->set('closed-milestones', $closedMilestones);


                echo "\tClosed milestone " . $ticket[3]['milestone'] . ".\n";
            }

        } catch (Exception $e) {
            throw $e;
            echo "Error creating issue for ticket #{$ticket_id}\n";
        }
    }

    $page += 1;

} while (count($ticket_ids) > 0);

return $issue_mapping;
//$migration->migrateQuery(
//    $config['trac-query'],
//    $config['gitlab-project']
//);
