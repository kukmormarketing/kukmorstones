<?php
declare(strict_types=1);
// Manually start this launcher after the original queue is complete.
// Subsequent Console requests continue the saved retry queue automatically.
$retryImages = true;
$batchSize = (int)($batchSize ?? 1);
$maxSeconds = (int)($maxSeconds ?? 15);
$imageTimeout = (int)($imageTimeout ?? 25);
require __DIR__ . '/console-auto.php';
