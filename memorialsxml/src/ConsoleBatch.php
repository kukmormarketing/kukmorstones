<?php
declare(strict_types=1);
namespace MemorialsXml;

use MODX\Revolution\modX;
use RuntimeException;
use Throwable;

/** Persistent, bounded Console runs. Each call handles only one phase. */
class ConsoleBatch
{
    public function __construct(private modX $modx, private array $config, private string $directory) {}

    private function save(array $state): void
    {
        $file = $this->directory . '/state.json';
        $tmp = $file . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
            throw new RuntimeException('Не удалось сохранить позицию импорта');
        }
        chmod($file, 0600);
    }

    protected function uploadImage(Importer $importer, int $productId, string $url): array
    {
        return $importer->importImages($productId, [$url]);
    }

    public function run(int $batchSize = 5, int $maxSeconds = 15, bool $reset = false): array
    {
        if ($batchSize < 1 || $batchSize > 100 || $maxSeconds < 1 || $maxSeconds > 60) {
            throw new RuntimeException('batchSize: 1..100, maxSeconds: 1..60');
        }
        if ((int)$this->config['root_id'] < 1) { throw new RuntimeException('Укажите root_id в config.php'); }
        $lockName = 'memorialsxml:' . substr(hash('sha256', $this->modx->getOption('dbname') . ':' . $this->modx->getOption('table_prefix')), 0, 48);
        $stmt = $this->modx->prepare('SELECT GET_LOCK(?, 0)');
        if (!$stmt || !$stmt->execute([$lockName]) || (int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Предыдущий импорт ещё работает. Дождитесь его завершения.');
        }
        try {
            return $this->lockedRun($batchSize, $maxSeconds, $reset);
        } finally {
            $stmt = $this->modx->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt) { $stmt->execute([$lockName]); }
        }
    }

    private function lockedRun(int $batchSize, int $maxSeconds, bool $reset): array
    {
        $deadline = microtime(true) + $maxSeconds;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Нет доступа к папке позиции импорта');
        }
        $signatureConfig = array_intersect_key($this->config, array_flip([
            'source', 'source_key', 'context', 'root_id', 'images', 'product_template',
            'category_template', 'publish_new_products', 'publish_new_categories',
            'currency', 'currency_aliases', 'duplicate_policy', 'zero_price_policy',
        ]));
        $signature = hash('sha256', json_encode($signatureConfig, JSON_THROW_ON_ERROR));
        $stateFile = $this->directory . '/state.json';
        $path = $this->directory . '/feed.xml';
        if ($reset) {
            foreach ([$stateFile, $path] as $file) { if (is_file($file)) { unlink($file); } }
        }
        // Interrupted gallery upload may leave a temporary image, never a checkpoint.
        foreach (glob($this->directory . '/image-*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        $feed = new Feed($this->config);
        $downloader = new Downloader($this->config);
        if (is_file($stateFile)) {
            $state = json_decode((string)file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
            if (($state['signature'] ?? '') !== $signature) {
                throw new RuntimeException('Конфигурация изменена. Для нового цикла задайте resetImport=true один раз.');
            }
            if (!is_file($path) || !hash_equals($state['feed_sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Снимок XML отсутствует/изменён. Начните новый цикл с resetImport=true.');
            }
        } else {
            Importer::log('batch_prepare', ['message' => 'Загрузка фиксированного снимка XML']);
            $downloader->snapshot($this->config['source'], $this->directory);
            $report = $feed->inspect($path);
            $state = [
                'version' => 1, 'signature' => $signature, 'feed_sha256' => hash_file('sha256', $path),
                'started_at' => date(DATE_ATOM), 'phase' => 'products', 'next_offset' => 0,
                'total_products' => $report['importable_offers'],
                'duplicate_records' => $report['duplicate_records'],
                'image_product' => 0, 'image_index' => 0, 'images_processed' => 0,
                'total_images' => 0, 'images_added' => 0, 'images_existing' => 0,
                'failed_products' => [], 'failed_images' => [], 'pending_image' => null,
            ];
            $this->save($state);
        }
        if (in_array($state['phase'], ['done', 'done_with_errors'], true)) { return $state; }
        $feed->ensureValidated($path);
        $offers = [];
        foreach ($feed->nodes($path, 'offer') as $node) {
            $o = $feed->offer($node);
            if ($feed->isDuplicate($o['data']['article'])) { continue; }
            if ($this->config['images']) {
                foreach ($o['pictures'] as $url) { $downloader->checkUrl($url); }
            }
            $offers[] = ['article' => $o['data']['article'], 'pictures' => $o['pictures']];
        }
        $state['total_images'] = array_sum(array_map(static fn(array $o): int => count($o['pictures']), $offers));
        $workConfig = $this->config;
        $workConfig['images'] = false; // Gallery has a separate cursor and request budget.
        $workConfig['image_timeout'] = min(10, max(1, (int)($this->config['image_timeout'] ?? 10)));
        $importer = new Importer($this->modx, $workConfig, $feed, $downloader, $this->directory, false);
        if ($state['phase'] === 'products') {
            $importer->run($path, $batchSize, $state['next_offset'], function (array $progress) use (&$state): void {
                $state['next_offset'] = $progress['next_offset'];
                if ($progress['error'] !== null) {
                    $state['failed_products'][] = ['article' => $progress['article'], 'error' => $progress['error']];
                }
                $this->save($state);
            }, $deadline);
            if ($state['next_offset'] >= count($offers)) {
                $state['phase'] = $this->config['images'] ? 'images' : 'done';
            }
        } elseif ($state['phase'] === 'images') {
            $handled = 0;
            $failedArticles = array_column($state['failed_products'], 'article');
            while ($state['image_product'] < count($offers) && $handled < $batchSize && microtime(true) < $deadline) {
                $o = $offers[$state['image_product']];
                if ($state['image_index'] >= count($o['pictures'])) {
                    $state['image_product']++;
                    $state['image_index'] = 0;
                    $this->save($state);
                    continue;
                }
                $url = $o['pictures'][$state['image_index']];
                $pending = $state['pending_image'];
                $attempts = $pending && $pending['product'] === $state['image_product'] && $pending['index'] === $state['image_index']
                    ? (int)$pending['attempts'] : 0;
                $state['pending_image'] = [
                    'product' => $state['image_product'], 'index' => $state['image_index'],
                    'article' => $o['article'], 'url' => $url, 'attempts' => $attempts + 1,
                ];
                $this->save($state); // Survives termination inside download/thumbnail generation.
                try {
                    if ($attempts >= 2) { throw new RuntimeException('Две предыдущие попытки не завершились; изображение пропущено'); }
                    if (in_array($o['article'], $failedArticles, true)) { throw new RuntimeException('Данные товара не были импортированы'); }
                    $product = $importer->findProduct($o['article']);
                    if (!$product) { throw new RuntimeException('Товар не найден по артикулу'); }
                    $result = $this->uploadImage($importer, (int)$product->get('id'), $url);
                    if ($result['image_errors'] > 0) { throw new RuntimeException('Ошибка загрузки; подробности в событии image_error'); }
                    $state['images_added'] += $result['images_added'];
                    $state['images_existing'] += $result['images_existing'];
                } catch (Throwable $e) {
                    $state['failed_images'][] = ['article' => $o['article'], 'url' => $url, 'error' => $e->getMessage()];
                    Importer::log('batch_image_error', end($state['failed_images']));
                }
                $state['pending_image'] = null;
                $state['image_index']++;
                $state['images_processed']++;
                $handled++;
                $this->save($state);
            }
            // Empty galleries do not require an extra run after the last picture.
            while ($state['image_product'] < count($offers) && $state['image_index'] >= count($offers[$state['image_product']]['pictures'])) {
                $state['image_product']++; $state['image_index'] = 0;
            }
            if ($state['image_product'] >= count($offers)) { $state['phase'] = 'done'; }
        }
        if ($state['phase'] === 'done' && ($state['failed_products'] || $state['failed_images'])) {
            $state['phase'] = 'done_with_errors';
        }
        $this->save($state);
        return $state;
    }
}
