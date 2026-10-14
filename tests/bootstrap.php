<?php
/**
 * FI_RUNTIME=revolt|amp|react|phasync|swoole selects the adapter (default revolt).
 * FI_DRIVER=generic forces PollDriver; FI_DRIVER=native requires the adapter's native driver.
 * MYSQLI_HOST, MYSQLI_PORT, MYSQLI_USER, MYSQLI_PASSWORD pick the server (default 127.0.0.1:13307 root/test).
 */
require __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) {
    require_once __DIR__ . '/../vendor/fiber-interop/api/tests/Support/ScopeStub.php';
}

$runtime = getenv('FI_RUNTIME') ?: 'revolt';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
async\setup(new $class());

$driver = getenv('FI_DRIVER') ?: 'generic';
if ($driver === 'generic') {
    async\mysqli\setup(new async\mysqli\PollDriver());
} elseif (async\mysqli\Registry::$candidates === []) {
    fwrite(STDERR, "No native async\\mysqli driver registered for $runtime\n");
    exit(1);
}
fwrite(STDERR, "runtime=$runtime driver=" . get_class(async\mysqli\driver()) . "\n");
