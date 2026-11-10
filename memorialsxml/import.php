#!/usr/bin/env php
<?php
declare(strict_types=1);
// CLI only: no unauthenticated HTTP endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
umask(0077);
require __DIR__ . '/src/Feed.php';
require __DIR__ . '/src/Downloader.php';
require __DIR__ . '/src/Importer.php';

use MemorialsXml\{Downloader, Feed, Importer};
use MODX\Revolution\{modUser, modX};

$options = getopt('', ['config:', 'source:', 'dry-run', 'inspect', 'limit:', 'no-images', 'help']);
if (isset($options['help'])) {
    echo "php import.php --config=/path/config.php [--source=/path/feed.xml|https://...] [--inspect|--dry-run] [--limit=10] [--no-images]\n";
    exit(0);
}
$tempDir = null;
$lock = null;
$dbLock = false;
$modx = null;
$exit = 1;
try {
    foreach (['xmlreader', 'simplexml', 'mbstring', 'curl', 'fileinfo'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException('Требуется PHP extension: ' . $extension); }
    }
    $configPath = $options['config'] ?? __DIR__ . '/config.php';
    if (!is_file($configPath)) { throw new RuntimeException('Создайте config.php из config.example.php'); }
    $config = require $configPath;
    if (!is_array($config)) { throw new RuntimeException('Конфигурация должна вернуть массив'); }
    foreach (array_keys(require __DIR__ . '/config.example.php') as $key) {
        if (!array_key_exists($key, $config)) { throw new RuntimeException('Отсутствует параметр config: ' . $key); }
    }
    if (!in_array($config['zero_price_policy'], ['preserve', 'import'], true)) { throw new RuntimeException('Некорректный zero_price_policy'); }
    if (!in_array($config['duplicate_policy'], ['error', 'skip'], true)) { throw new RuntimeException('Некорректный duplicate_policy'); }
    if (isset($options['source'])) { $config['source'] = $options['source']; }
    if (isset($options['no-images'])) { $config['images'] = false; }
    $limit = $options['limit'] ?? '0';
    if (!is_string($limit) || !preg_match('/^\d+$/D', $limit)) { throw new RuntimeException('limit должен быть целым >= 0'); }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $config['source_key'])) { throw new RuntimeException('Недопустимый source_key'); }
    $lockPath = sys_get_temp_dir() . '/memorialsxml-' . hash('sha256', $config['modx_base_path']) . '.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Другой импорт этого сайта уже запущен'); }
    $tempDir = sys_get_temp_dir() . '/memorialsxml-' . bin2hex(random_bytes(12));
    if (!mkdir($tempDir, 0700)) { throw new RuntimeException('Не удалось создать временный каталог'); }
    $downloader = new Downloader($config);
    $path = $downloader->snapshot($config['source'], $tempDir);
    $feed = new Feed($config);
    $report = $feed->inspect($path);
    if ($config['images']) {
        // Validate every image destination before any DB writes.
        foreach ($feed->nodes($path, 'offer') as $node) {
            foreach ($feed->offer($node)['pictures'] as $url) { $downloader->checkUrl($url); }
        }
    }
    Importer::log('validated', $report);
    if (isset($options['inspect'])) {
        $exit = 0;
    } else {
        $base = rtrim($config['modx_base_path'], '/') . '/';
        if (!is_file($base . 'config.core.php')) { throw new RuntimeException('Не найден MODX config.core.php'); }
        define('MODX_API_MODE', true);
        require_once $base . 'config.core.php';
        require_once MODX_CORE_PATH . 'vendor/autoload.php';
        $modx = new modX();
        $modx->initialize('mgr');
        $user = $modx->getObject(modUser::class, (int)$config['manager_user_id']);
        if (!$user || !$user->get('active') || !$user->get('sudo')) {
            throw new RuntimeException('manager_user_id должен быть активным пользователем MODX с sudo=true');
        }
        $modx->user = $user;
        $modx->services->get('ms3')->initialize('mgr');
        $modx->services->get('ms3')->loadMap();
        // MySQL named lock also prevents competing CLI runs on different web nodes.
        $dbLockName = 'memorialsxml:' . substr(hash('sha256', $modx->getOption('dbname') . ':' . $modx->getOption('table_prefix')), 0, 48);
        $stmt = $modx->prepare('SELECT GET_LOCK(?, 0)');
        if (!$stmt || !$stmt->execute([$dbLockName]) || (int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Другой импорт использует эту базу MODX');
        }
        $dbLock = true;
        $importer = new Importer($modx, $config, $feed, $downloader, $tempDir, isset($options['dry-run']));
        $stats = $importer->run($path, (int)$limit);
        $exit = $stats['failed'] || $stats['image_errors'] || $stats['skipped_duplicates'] ? 2 : 0;
    }
} catch (Throwable $e) {
    Importer::log('fatal', ['error' => $e->getMessage()]);
} finally {
    if ($dbLock && $modx) {
        $stmt = $modx->prepare('SELECT RELEASE_LOCK(?)');
        if ($stmt) { $stmt->execute([$dbLockName]); }
    }
    if ($tempDir && is_dir($tempDir)) {
        foreach (glob($tempDir . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($tempDir);
    }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($exit);
