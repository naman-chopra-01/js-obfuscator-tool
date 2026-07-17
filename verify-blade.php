<?php
// Usage: php verify-blade.php <blade-file> <laravel-project-root-with-vendor>
// Compiles the given blade file with Laravel's real Blade compiler and lints
// the resulting PHP. Exit 0 = safe, exit 1 = would break at runtime.

$file        = $argv[1];
$projectRoot = rtrim($argv[2], '/\\');

require $projectRoot . '/vendor/autoload.php';

$filesystem = new Illuminate\Filesystem\Filesystem;
$cacheDir   = sys_get_temp_dir() . '/blade_verify_cache_' . getmypid() . '_' . bin2hex(random_bytes(4));
@mkdir($cacheDir, 0777, true);
$compiler = new Illuminate\View\Compilers\BladeCompiler($filesystem, $cacheDir);

try {
    $compiled = $compiler->compileString(file_get_contents($file));
} catch (\Throwable $e) {
    fwrite(STDERR, 'compile error: ' . $e->getMessage() . PHP_EOL);
    $filesystem->deleteDirectory($cacheDir);
    exit(1);
}

$tmp = $cacheDir . '/check.php';
file_put_contents($tmp, $compiled);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
$filesystem->deleteDirectory($cacheDir);

if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out) . PHP_EOL);
    exit(1);
}

exit(0);