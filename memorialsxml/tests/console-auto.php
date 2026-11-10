<?php
declare(strict_types=1);
// Test the real launcher against the native Console completed-session protocol.
$dir = sys_get_temp_dir() . '/memorials-auto-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
copy(dirname(__DIR__) . '/console-auto.php', $dir . '/console-auto.php');
file_put_contents($dir . '/console-batch.php', <<<'FIXTURE'
<?php
$_SESSION['fixture_calls'] = ($_SESSION['fixture_calls'] ?? 0) + 1;
if (($_SESSION['fixture_error'] ?? false)) { echo 'Simulated batch error'; return; }
$phases = ['products', 'images', 'done'];
$state = ['phase' => $_SESSION['fixture_phase'] ?? $phases[min($_SESSION['fixture_calls'] - 1, 2)]];
if ($resetImport) { throw new RuntimeException('Repeated automatic reset'); }
if (!$automaticRepeat) { throw new RuntimeException('Automatic mode not passed to launcher'); }
FIXTURE);
function verify(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . PHP_EOL;
}
try {
    $_SESSION = [];
    $resetImport = true;
    require $dir . '/console-auto.php';
    verify($_SESSION['Console']['completed'] === false, 'Products trigger another Console request');
    require $dir . '/console-auto.php';
    verify($_SESSION['Console']['completed'] === false, 'Images trigger another Console request');
    $_SESSION['fixture_phase'] = 'retry_images';
    require $dir . '/console-auto.php';
    verify($_SESSION['Console']['completed'] === false, 'Failed-image retries trigger another Console request');
    unset($_SESSION['fixture_phase']);
    require $dir . '/console-auto.php';
    verify($_SESSION['Console']['completed'] === true, 'Done stops automatic requests');
    $_SESSION['fixture_phase'] = 'done_with_errors';
    require $dir . '/console-auto.php';
    verify($_SESSION['Console']['completed'] === true, 'Completion with reported errors stops requests');
    $_SESSION['Console']['completed'] = false;
    $_SESSION['fixture_error'] = true;
    $state = ['phase' => 'products'];
    ob_start();
    require $dir . '/console-auto.php';
    ob_end_clean();
    verify($_SESSION['Console']['completed'] === true && !isset($state), 'Errors stop requests; stale state is discarded');
    echo 'OK: native Console automatic-repeat protocol' . PHP_EOL;
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
    rmdir($dir);
}
