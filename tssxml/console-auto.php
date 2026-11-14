<?php
declare(strict_types=1);
// Native Console protocol: completed=false requests another execution of the code.
// Default to stopped so a failed batch never creates an automatic error loop.
$_SESSION['Console']['completed'] = true;
unset($state);
$batchSize = (int)($batchSize ?? 20);
$maxSeconds = (int)($maxSeconds ?? 15);
// A reset must be performed once through the manual launcher, never each repetition.
$resetImport = false;
$automaticRepeat = true;
require __DIR__ . '/console-batch.php';
if (isset($state) && is_array($state) && isset($state['phase'])) {
    $_SESSION['Console']['completed'] = in_array($state['phase'], ['done', 'done_with_errors'], true);
}
