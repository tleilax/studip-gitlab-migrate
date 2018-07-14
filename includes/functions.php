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


function translateTracToMarkdown($text, $trac_url) {
    $text = str_replace("\r\n", "\n", $text);
    // Inline code block
    $text = preg_replace('/{{{(.*?)}}}/', '`$1`', $text);
    // Multiline code block (optionally with language description)
    $text = preg_replace("/{{{\n(?:#!(.+?)\n)?(.*?)\n}}}/s", "```\$1\n\$2\n```", $text);

    // Headers
    $text = preg_replace('/(?m)^======\s+(.*?)(\s+======)?$/', '###### $1', $text);
    $text = preg_replace('/(?m)^=====\s+(.*?)(\s+=====)?$/', '##### $1', $text);
    $text = preg_replace('/(?m)^====\s+(.*?)(\s+====)?$/', '#### $1', $text);
    $text = preg_replace('/(?m)^===\s+(.*?)(\s+===)?$/', '### $1', $text);
    $text = preg_replace('/(?m)^==\s+(.*?)(\s+==)?$/', '## $1', $text);
    $text = preg_replace('/(?m)^=\s+(.*?)(\s+=)?$/', '# $1', $text);
    // Bullet points
    $text = preg_replace('/^             \* /', '****', $text);
    $text = preg_replace('/^         \* /', '***', $text);
    $text = preg_replace('/^     \* /', '**', $text);
    $text = preg_replace('/^ \* /', '*', $text);
    $text = preg_replace('/^ \d+\. /', '1.', $text);
    // Make sure that horizontal rules have a line before them
    $text = preg_replace("/(?m)^-{4,}$/", "\n----", $text);

    $lines = array();
    $isTable = false;
    $isCode  = false;
    foreach (explode("\n", $text) as $line) {
        if (strpos($line, '```') === 0) {
            $isCode = !$isCode;
        }

        // Don't mess with code
        if (!$isCode) {
            // External links
            $line = preg_replace('/\[(https?:\/\/[^\s\[\]]+)\s([^\[\]]+)\]/', '[$2]($1)', $line);
            // Plain images (not linking to something specific)
            $line = preg_replace('/\[\[Image\((?!wiki|ticket|htdocs|source)(.+?)\)\]\]/', '![image]($1)', $line);
            // Remove the unnecessary exclamation mark in !WikiLinkBreaker
            $line = preg_replace('/\!(([A-Z][a-z0-9]+){2,})/', '$1', $line);
            // '''bold'''
            $line = preg_replace("/'''([^']*?)'''/", '**$1**', $line);
            // ''italic''
            $line = preg_replace("/''(.*?)''/", '_$1_', $line);
            // //italic//
            $line = preg_replace("/\/\/(.*?)\/\//", '_$1_', $line);
            // #Ticket links
            $line = preg_replace('/#(\d+)/', '[#$1](' . $trac_url . '/ticket/$1)', $line);
            $line = preg_replace('/ticket:(\d+)/', '[ticket:$1](' . $trac_url . '/ticket/$1)', $line);
            // [changeset] links
            $line = preg_replace('/\[(\d+)\]/', '[[$1]](' . $trac_url . '/changeset/$1)', $line);
            $line = preg_replace('/changeset:(\d+)/', '[changeset:$1](' . $trac_url . '/changeset/$1)', $line);
            $line = preg_replace('/r(\d+)/', '[r$1](' . $trac_url . '/changeset/$1)', $line);
            // {report} links
            $line = preg_replace('/{(\d+)}/', '[{$1}](' . $trac_url . '/report/$1)', $line);
            $line = preg_replace('/report:(\d+)/', '[report:$1](' . $trac_url . '/report/$1)', $line);

            if (strpos($line, '||') !== 0) {
                $isTable = false;
            } else {
                // Makes sure both that there's a new line before the table and that a table header is generated
                if (!$isTable) {
                    $sep = preg_replace('/[^|]/', '-', $line);
                    $line = "\n$line\n$sep";
                    $isTable = true;
                }
                // Makes sure that there's a space after the cell separator, since |cell| works in WikiFormatting but not in GFM
                $line = preg_replace('/\|\|/', '| ', $line);
                // Make trac headers bold
                $line = preg_replace('/= (.+?) =/', '**$1**', $line);
            }
        }

        $lines[] = $line;
    }
    $text = implode("\n", $lines);

    return $text;
}
