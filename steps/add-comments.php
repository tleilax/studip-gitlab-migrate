<?php
use Trac2GitLab\Migration;

$migration = new Migration(
    $config['gitlab-api-url'],
    $config['gitlab-access-token'],
    $config['gitlab-admin-token'],
    $config['trac-url'],
    $config['create-trac-links'],
    $userMapping = []
);
$trac   = $migration->trac;
$gitlab = $migration->gitLab->getClient();

var_dump($trac->getClient()->execute('ticket.query', ['version=trunk&max=0']));
die;

foreach ($result['issues'] as $trac_id => $gitlab_id) {
    $comments = $trac->getComments($trac_id);

    foreach ($comments as $comment) {
        $text = $comment['text'];


//         In [changeset:"48002"]:
// {{{
// #!CommitTicketReference repository="" revision="48002"
// fixes #8641
// }}}
        // try {
        //     $gitlab->api('issues')->addComment($config['gitlab-project'], $gitlab_id, [
        //         'body'       => $comment['text'],
        //         'sudo'       => $comment['author'],
        //         'created_at' => $comment['time']['__jsonclass__'][1],
        //     ]);
        // } catch (Exception $e) {
            $gitlab->api('issues')->addComment($config['gitlab-project'], $gitlab_id, [
                'body'       => $text,
                'created_at' => $comment['time']['__jsonclass__'][1],
                'updated_at' => $comment['time']['__jsonclass__'][1],
            ]);
        // }
    }
}
