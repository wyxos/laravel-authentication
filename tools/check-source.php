<?php

// Count PHP code-bearing lines, excluding whitespace and comments.
$failed = false;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php' || preg_match('~[/\\\\](vendor|\.git|\.phpunit\.cache)[/\\\\]~', $file->getPathname())) {
        continue;
    }
    $lines = [];
    $line = 1;
    foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $ignored = is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
        foreach (explode("\n", $text) as $offset => $part) {
            if (! $ignored && trim($part) !== '') {
                $lines[$line + $offset] = true;
            }
        }
        $line += substr_count($text, "\n");
    }
    if (count($lines) > 500) {
        fwrite(STDERR, $file->getPathname().' exceeds 500 PHP source lines.'.PHP_EOL);
        $failed = true;
    }
}
if (! $failed) {
    echo 'All first-party PHP files satisfy the 500-line source limit.'.PHP_EOL;
}
exit($failed ? 1 : 0);
