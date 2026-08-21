<?php
$nodeTest = __DIR__.'/extension_runtime_test.js';
$nodeOutput = [];
$nodeExitCode = 0;
$nodeBinary = getenv('NODE_BINARY') ?: 'node';
exec(escapeshellarg($nodeBinary).' '.escapeshellarg($nodeTest).' 2>&1', $nodeOutput, $nodeExitCode);
echo implode(PHP_EOL, $nodeOutput).PHP_EOL;
if ($nodeExitCode !== 0) {
    fwrite(STDERR, "Extension runtime tests failed (exit $nodeExitCode).\n");
    exit($nodeExitCode ?: 1);
}

require_once __DIR__.'/TestCase.php';
$files = glob(__DIR__.'/test_*.php');
$passed=0;
foreach($files as $f){ require $f; }
foreach(get_defined_functions()['user'] as $fn){ if(str_starts_with($fn,'test_')){ $fn(); $passed++; echo "."; }}
echo "\n$passed tests passed\n";
