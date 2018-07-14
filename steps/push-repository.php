<?php
echo "> Pushing repository...";
my_exec([
    ['git -C %s remote add origin %s', $config['temp-path'], $config['gitlab-repo-url']],
    ['git -C %s push origin master', $config['temp-path']]
]);
echo "done\n";
