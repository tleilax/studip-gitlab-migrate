<?php
// Rewrite ticket numbers to issue numbers in commits
echo "> rewrite commit messages...";
$index = 0;
// Skip latest entry for now
foreach (array_reverse(array_slice($commit_mapping, 1)) as $sha1) {
    $command = sprintf(
        "git log -C %s --color=never --format=%%B -1 %s",
        $config['temp-path'],
        $sha1
    );
    $message = my_exec(
        "git -C %s log --color=never --format=%%B -1 %s",
        $config['temp-path'],
        $sha1,
        function ($message) use ($result) {
            // Replace tickets/issues
            $message = preg_replace_callback('/(?<=\s|^|,)#(\d+)\b/', function ($match) use ($result) {
                if (isset($result['issues'][$match[1]])) {
                    return '#' . $result['issues'][$match[1]];
                }
                return $match[0];
            }, $message);

            // Replace changesets/commits
            $message = preg_replace_callback('/(?<=\s|^|,)\[(\d+(?:,\d+)*)\](?=\s|$|,)/', function ($match) use ($result) {
                $changesets = explode(',', $match[1]);
                foreach ($changesets as $index => $changeset) {
                    if (isset($result['commits'][$changeset])) {
                        $changesets[$index] = $result['commits'][$changeset];
                    }
                }

                return '[' . implode(',', $changesets) . ']';
            }, $message);

            return $message;
        }
    );

    my_exec([
        ['git -C %s checkout -b amending HEAD~%u', $config['temp-path'], $index + 1],
        ['git -C %s cherry-pick %s', $config['temp-path'], $sha1],
        ['git -C %s commit --amend -m "%s"', $config['temp-path'], addslashes($message)],
        ['git -C %s rebase --onto amending %s master', $config['temp-path'], $sha1],
        ['git -C %s branch -d amending', $config['temp-path']],
    ]);

    unset($commit_mapping[$sha1]);

    $index += 1;
}
echo "done\n";
