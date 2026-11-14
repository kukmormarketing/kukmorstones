<?php
declare(strict_types=1);
// A separate cursor: no category/product writes and no changes to the main queue.
$imagesOnly = true;
$loadImages = true;
$retryImages = (bool)($retryImages ?? false);
$batchSize = (int)($batchSize ?? 1);
$maxSeconds = (int)($maxSeconds ?? 15);
$imageTimeout = (int)($imageTimeout ?? 20);
require __DIR__ . '/console-auto.php';
