<?php
declare(strict_types=1);
// Include from the authenticated MODX Console. This file is not an HTTP endpoint.
if (!isset($modx) || !$modx instanceof \MODX\Revolution\modX || !$modx->user || !$modx->user->get('sudo')) {
    http_response_code(403);
    return;
}
try {
    require_once __DIR__ . '/src/Feed.php';
    require_once __DIR__ . '/src/Downloader.php';
    require_once __DIR__ . '/src/Importer.php';
    require_once __DIR__ . '/src/ConsoleBatch.php';
    $config = array_replace(require __DIR__ . '/config.example.php', require __DIR__ . '/config.php');
    $config['modx_base_path'] = MODX_BASE_PATH;
    $config['images'] = !empty($imagesOnly) || (bool)($loadImages ?? false);
    if (!empty($imagesOnly)) {
        if (!defined('TssXml\\ConsoleBatch::IMAGES_ONLY_SUPPORTED')) {
            throw new \RuntimeException('Обновите модуль ТСС до версии 1.1.0');
        }
        $config['images_only'] = true;
    }
    $config['image_timeout'] = (int)($imageTimeout ?? $config['image_timeout'] ?? 20);
    // Prevent long waits for a single remote response.
    $config['http_timeout'] = min(60, (int)$config['http_timeout']);
    $modx->services->get('ms3')->initialize('mgr');
    $modx->services->get('ms3')->loadMap();
    $directory = MODX_CORE_PATH . (!empty($imagesOnly) ? 'cache/tssxml-images-only/' : 'cache/tssxml-batch/') . substr(hash('sha256', $config['source_key'] . ':' . $config['context'] . ':' . $config['root_id']), 0, 24);
    $runner = new \TssXml\ConsoleBatch($modx, $config, $directory);
    $state = $runner->run((int)($batchSize ?? 5), (int)($maxSeconds ?? 15), (bool)($resetImport ?? false), (bool)($retryImages ?? false));
    echo "\nФаза: " . $state['phase'] . "\n";
    if (!empty($imagesOnly)) {
        echo "Режим: только изображения существующих товаров\n";
        echo 'Не найдены товары: ' . count($state['missing_products'] ?? []) . "\n";
        echo 'Пропущено картинок отсутствующих товаров: ' . ($state['missing_product_images'] ?? 0) . "\n";
    } else {
        echo 'Товары: ' . $state['next_offset'] . '/' . $state['total_products'] . "\n";
    }
    if ($config['images']) {
        echo 'Изображения: ' . $state['images_processed'] . '/' . $state['total_images'] . "\n";
        echo 'Добавлено изображений: ' . $state['images_added'] . "\n";
        echo 'Уже в галерее: ' . $state['images_existing'] . "\n";
    } else {
        echo "Изображения отключены: обрабатываются товары и характеристики.\n";
    }
    echo 'Ошибки товаров: ' . count($state['failed_products']) . "\n";
    echo 'Ошибки изображений: ' . count($state['failed_images']) . "\n";
    echo 'Пропущено записей с повторным артикулом: ' . $state['duplicate_records'] . "\n";
    if (isset($state['retry_total'])) {
        echo 'Повтор изображений: ' . $state['retry_index'] . '/' . $state['retry_total'] . "\n";
        echo 'Успешно при повторе: ' . $state['retry_success'] . "\n";
    }
    if ($state['phase'] === 'done_with_errors' && $state['failed_images']) {
        echo 'Последние ошибки изображений: ' . htmlspecialchars(json_encode(array_slice($state['failed_images'], -3), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    }
    echo 'Позиция и ошибки: ' . $directory . "/state.json\n";
    echo in_array($state['phase'], ['done', 'done_with_errors'], true)
        ? "Цикл завершён. При наличии ошибок проверьте state.json.\n"
        : (!empty($automaticRepeat)
            ? "Следующая порция запустится автоматически через Console.\n"
            : "Повторите тот же код Console: продолжится следующая порция.\n");
} catch (\Throwable $e) {
    echo "\nОшибка: " . $e->getMessage() . "\n";
}
