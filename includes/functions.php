<?php
function my_exec($command) {
    static $level = 0;

    $level += 1;

    if (func_num_args() === 1 && is_array($command)) {
        return array_map(function ($args) {
            return call_user_func_array('my_exec', $args);
        }, $command);
    }

    $args = func_get_args();
    $command = array_shift($args);
    $callback = is_callable(end($args))
              ? array_pop($args)
              : function ($i) { return $i; };

    $command = vsprintf($command, array_slice(func_get_args(), 1));
    exec($command, $output);
    $result = $callback(implode("\n", $output));

    $level -= 1;

    return $result;
}

function removeDirectory($directory)
{
    $it = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($directory);
}
