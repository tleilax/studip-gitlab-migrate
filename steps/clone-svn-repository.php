<?php
define('LOG_REGEXP', '~^r(\d+) = ([0-9a-f]+) \(refs/remotes/git-svn\)~');

// Remove old git directory
if (file_exists($config['temp-path'])) {
    rename($config['temp-path'], $config['temp-path'] . '-' . md5(uniqid('conversion', true)));
}

// Clone svn repository as git
$commit_mapping = [];

echo "> git svn clone...";
my_exec(
    'git svn clone -r%u:HEAD --no-metadata --authors-file=%s svn://develop.studip.de/studip/trunk %s 2> /dev/null',
    $config['trac-revision'],
    $config['trac-users'],
    $config['temp-path'],
    function ($output) use (&$commit_mapping) {
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (!preg_match(LOG_REGEXP, $line, $match)) {
                continue;
            }

            $commit_mapping[$match[1]] = $match[2];
        }

        return $output;
    }
);
echo "done\n";

return $commit_mapping;
