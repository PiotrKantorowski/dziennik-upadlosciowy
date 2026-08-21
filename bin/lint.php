<?php
$root = dirname(__DIR__);
$ok = true;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $cmd = 'php -l '.escapeshellarg($file->getPathname()).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) { $ok=false; echo implode("\n", $out)."\n"; }
}
if (!$ok) exit(1);
echo "PHP lint OK\n";
