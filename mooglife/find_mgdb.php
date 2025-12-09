<?php
// Scan all project files for function mg_db
$root = __DIR__;

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root)
);

foreach ($rii as $file) {
    if ($file->isDir()) continue;

    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') continue;

    $contents = file_get_contents($path);
    if (strpos($contents, 'function mg_db') !== false) {
        echo "<p><strong>FOUND mg_db() in:</strong> $path</p>";
    }
}
