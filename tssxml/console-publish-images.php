<?php
declare(strict_types=1);
$_SESSION['Console']['completed'] = true;
unset($state, $publication);
if (!isset($modx) || !$modx instanceof \MODX\Revolution\modX || !$modx->user || !$modx->user->get('sudo')) { http_response_code(403); return; }
try {
    require_once __DIR__ . '/src/Feed.php';
    require_once __DIR__ . '/src/Downloader.php';
    require_once __DIR__ . '/src/Importer.php';
    require_once __DIR__ . '/src/PublishBatch.php';
    $config = array_replace(require __DIR__ . '/config.example.php', require __DIR__ . '/config.php');
    $modx->services->get('ms3')->initialize('mgr');
    $modx->services->get('ms3')->loadMap();
    $key = substr(hash('sha256', $config['source_key'] . ':' . $config['context'] . ':' . $config['root_id']), 0, 24);
    $publication = (new \TssXml\PublishBatch($modx, $config, MODX_CORE_PATH . 'cache/tssxml-publish/' . $key))->run((int)($publishBatchSize ?? 20), (int)($maxSeconds ?? 15));
    echo 'Публикация: ' . $publication['cursor'] . '/' . $publication['total'] . "\n";
    echo 'Опубликовано: ' . $publication['published'] . "\n";
    echo 'Уже опубликованы: ' . $publication['already_published'] . "\n";
    echo 'Ошибок публикации: ' . count($publication['errors']) . "\n";
    if ($publication['phase'] === 'publishing') {
        $_SESSION['Console']['completed'] = false;
        echo "Следующая порция публикации запустится автоматически.\n";
        return;
    }
    if ($publication['errors']) {
        echo 'Последние ошибки публикации: ' . htmlspecialchars(json_encode(array_slice($publication['errors'], -3), JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    }
    // Optional, request-local workaround for a custom parser / Media Source issue.
    // No system settings are saved. Publication above uses the normal site parser.
    if (!empty($useCoreParser)) {
        $coreParser = new \MODX\Revolution\modParser($modx);
        $modx->parser = $coreParser;
        if (!$modx->services->has('parser')) { $modx->services->add('parser', $coreParser); }
    }
    $imagesOnly = true;
    $loadImages = true;
    $retryImages = (bool)($retryImages ?? false);
    $resetImport = false;
    $automaticRepeat = true;
    $batchSize = (int)($imageBatchSize ?? 1);
    $imageTimeout = (int)($imageTimeout ?? 20);
    require __DIR__ . '/console-batch.php';
    if (isset($state['phase'])) {
        $_SESSION['Console']['completed'] = in_array($state['phase'], ['done', 'done_with_errors'], true);
    }
} catch (\Throwable $e) {
    $_SESSION['Console']['completed'] = true;
    echo "\nОшибка: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
}
