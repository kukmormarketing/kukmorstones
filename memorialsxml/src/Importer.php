<?php
declare(strict_types=1);
namespace MemorialsXml;

use MiniShop3\Model\{msCategory, msCategoryOption, msOption, msProduct, msProductData, msProductFile, msProductOption};
use MODX\Revolution\{modResource, modX};
use RuntimeException;
use Throwable;

final class Importer
{
    private array $imageFailures = [];
    private array $categoryMap = [];
    private array $categoryExisting = [];
    private array $definitions = [];
    private array $bindings = [];
    private int $virtualId = -1;
    private array $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'skipped_duplicates' => 0, 'images_added' => 0, 'images_existing' => 0, 'image_errors' => 0];

    public function __construct(
        private modX $modx,
        private array $config,
        private Feed $feed,
        private Downloader $downloader,
        private string $tempDir,
        private bool $dryRun,
    ) {}

    public static function log(string $event, array $data = []): void
    {
        echo json_encode(['time' => date(DATE_ATOM), 'event' => $event] + $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    }

    private function process(string $class, array $payload): array
    {
        $response = $this->modx->runProcessor($class, $payload);
        if (!$response || $response->isError()) {
            throw new RuntimeException($class . ': ' . json_encode($response ? $response->getAllErrors() : ['Нет ответа'], JSON_UNESCAPED_UNICODE));
        }
        return (array)$response->getObject();
    }

    private function existingPayload(modResource $resource): array
    {
        // Preserve checkbox fields, template and publication when updating via resource processors.
        $fields = $this->modx->getFields(modResource::class);
        return array_intersect_key($resource->toArray(), $fields);
    }

    private function product(string $article): ?msProduct
    {
        $q = $this->modx->newQuery(msProduct::class);
        $q->innerJoin(msProductData::class, 'ImportData', 'ImportData.id = msProduct.id');
        $q->where(['ImportData.article' => $article, 'msProduct.context_key' => $this->config['context']]);
        $q->limit(2);
        $matches = array_values($this->modx->getCollection(msProduct::class, $q));
        if (count($matches) > 1) { throw new RuntimeException('Несколько товаров MODX с артикулом ' . $article); }
        $p = $matches[0] ?? null;
        if ($p && $p->get('deleted')) { throw new RuntimeException('Товар с артикулом в корзине MODX: ' . $article); }
        return $p;
    }

    private function category(string $id, array $categories): int
    {
        if (isset($this->categoryMap[$id])) { return $this->categoryMap[$id]; }
        $c = $categories[$id];
        $parent = $c['parent'] === '' ? (int)$this->config['root_id'] : $this->category($c['parent'], $categories);
        $alias = 'memorials-cat-' . substr(hash('sha256', $this->config['source_key'] . ':' . $id), 0, 24);
        $existing = $this->categoryExisting[$id] ?? null;
        $properties = $existing ? (array)$existing->get('properties') : [];
        if ($existing && $existing->get('deleted')) {
            throw new RuntimeException('Импортированная категория в корзине MODX: ' . $id);
        }
        if ($existing && (string)$existing->get('pagetitle') === $c['name']
            && (int)$existing->get('parent') === $parent) {
            return $this->categoryMap[$id] = (int)$existing->get('id');
        }
        if ($this->dryRun) {
            $cid = $existing ? (int)$existing->get('id') : $this->virtualId--;
        } else {
            $properties['memorialsxml'] = ['source' => $this->config['source_key'], 'category_id' => $id];
            $payload = $existing ? $this->existingPayload($existing) : [
                'template' => $this->config['category_template'],
                'published' => (int)$this->config['publish_new_categories'],
                'hidemenu' => 0,
            ];
            $payload = array_replace($payload, [
                'pagetitle' => $c['name'], 'parent' => $parent,
                'class_key' => msCategory::class, 'context_key' => $this->config['context'],
                'properties' => $properties,
            ]);
            if (!$existing) { $payload['alias'] = $alias; }
            if ($existing) { $payload['id'] = (int)$existing->get('id'); }
            $result = $this->process('MiniShop3\\Processors\\Category\\' . ($existing ? 'Update' : 'Create'), $payload);
            $cid = (int)($result['id'] ?? 0);
            if ($cid < 1) { throw new RuntimeException('Процессор не вернул ID категории'); }
            $saved = $this->modx->getObject(modResource::class, $cid);
            $stored = $saved ? (array)$saved->get('properties') : [];
            if (($stored['memorialsxml']['category_id'] ?? '') !== $id) {
                throw new RuntimeException('Не сохранилась привязка категории к XML');
            }
        }
        self::log('category', ['external_id' => $id, 'id' => $cid, 'action' => $existing ? 'update' : 'create', 'dry_run' => $this->dryRun]);
        return $this->categoryMap[$id] = $cid;
    }

    private function options(int $productId, int $categoryId, array $options): void
    {
        if ($productId < 1 || $categoryId < 1) {
            throw new RuntimeException('Некорректный ID товара или категории для характеристик');
        }
        $values = [];
        foreach ($options as $key => $o) {
            if (!isset($this->definitions[$key])) {
                $definition = $this->modx->getObject(msOption::class, ['key' => $key]);
                if (!$definition) {
                    $definition = $this->modx->newObject(msOption::class);
                    $definition->fromArray([
                        'key' => $key, 'caption' => mb_substr($o['caption'], 0, 191),
                        'measure_unit' => $o['unit'], 'type' => 'textfield',
                        'description' => 'XML: ' . $o['caption'],
                    ]);
                    if (!$definition->save()) { throw new RuntimeException('Не удалось создать опцию: ' . $key); }
                }
                $optionId = (int)$definition->get('id');
                if ($optionId < 1) { throw new RuntimeException('Опция не имеет положительного ID: ' . $key); }
                $this->definitions[$key] = $optionId;
            }
            $bindingKey = $categoryId . ':' . $key;
            if (!isset($this->bindings[$bindingKey])) {
                $criteria = ['category_id' => $categoryId, 'option_id' => $this->definitions[$key]];
                if (!$this->modx->getObject(msCategoryOption::class, $criteria)) {
                    $binding = $this->modx->newObject(msCategoryOption::class);
                    // Both IDs form the primary key. xPDO fromArray() skips them
                    // unless setPrimaryKeys=true; assign explicitly to avoid (0,0).
                    $binding->set('category_id', $categoryId);
                    $binding->set('option_id', $this->definitions[$key]);
                    $binding->set('active', 1);
                    $binding->set('required', 0);
                    if ((int)$binding->get('category_id') !== $categoryId
                        || (int)$binding->get('option_id') !== $this->definitions[$key]) {
                        throw new RuntimeException('Не установлены ID привязки характеристики: ' . $key);
                    }
                    if (!$binding->save()) { throw new RuntimeException('Не удалось привязать опцию к категории'); }
                }
                $this->bindings[$bindingKey] = true;
            }
            $values[$key] = $o['values'];
        }
        $writer = $this->modx->newObject(msProductOption::class);
        if (!$writer->saveProductOptions($productId, $values, false)) {
            throw new RuntimeException('Не удалось сохранить характеристики');
        }
        // The beta service can return true despite SQL errors; verify persisted values.
        $actual = $writer->getForProduct($productId, array_keys($values));
        foreach ($values as $key => $expected) {
            $received = array_map('strval', $actual[$key] ?? []);
            sort($received); sort($expected);
            if ($received !== $expected) { throw new RuntimeException('Ошибка проверки характеристики: ' . $key); }
        }
    }

    private function images(int $productId, array $urls): void
    {
        foreach ($urls as $url) {
            $file = null;
            try {
                $path = parse_url($url, PHP_URL_PATH);
                $ext = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    throw new RuntimeException('Неподдерживаемое расширение изображения');
                }
                $file = $this->tempDir . '/image-' . hash('sha256', $url) . '.' . $ext;
                self::log('image_start', ['product_id' => $productId, 'url' => $url]);
                $this->downloader->download($url, $file, (int)$this->config['max_image_bytes'], (int)($this->config['image_timeout'] ?? 20));
                $size = @getimagesize($file);
                if (!$size || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)
                    || $size[0] * $size[1] > $this->config['max_image_pixels']) {
                    throw new RuntimeException('Файл не является допустимым изображением');
                }
                $model = $this->modx->newObject(msProductFile::class);
                $hash = $model->generateHash($file);
                if ($this->modx->getCount(msProductFile::class, ['product_id' => $productId, 'parent_id' => 0, 'hash' => $hash])) {
                    $this->stats['images_existing']++;
                    continue;
                }
                $this->process('MiniShop3\\Processors\\Gallery\\Upload', ['id' => $productId, 'file' => $file]);
                $this->stats['images_added']++;
            } catch (Throwable $e) {
                $this->stats['image_errors']++;
                $this->imageFailures[] = ['product_id' => $productId, 'url' => $url, 'error' => $e->getMessage()];
                self::log('image_error', ['product_id' => $productId, 'url' => $url, 'error' => $e->getMessage()]);
            } finally {
                if ($file && is_file($file)) { unlink($file); }
            }
        }
    }

    public function findProduct(string $article): ?msProduct
    {
        return $this->product($article);
    }

    public function importImages(int $productId, array $urls): array
    {
        $before = $this->stats;
        $failureCount = count($this->imageFailures);
        $this->images($productId, $urls);
        return [
            'images_added' => $this->stats['images_added'] - $before['images_added'],
            'images_existing' => $this->stats['images_existing'] - $before['images_existing'],
            'image_errors' => $this->stats['image_errors'] - $before['image_errors'],
            'errors' => array_slice($this->imageFailures, $failureCount),
        ];
    }

    public function run(string $path, int $limit = 0, int $offset = 0, ?callable $checkpoint = null, float $deadline = 0.0): array
    {
        if ($offset < 0 || $limit < 0) { throw new RuntimeException('Отрицательный offset/limit'); }
        $this->stats['next_offset'] = $offset;
        $this->feed->ensureValidated($path);
        $categories = $this->feed->categories($path);
        $root = $this->modx->getObject(msCategory::class, (int)$this->config['root_id']);
        if (!$root || $root->get('deleted') || $root->get('context_key') !== $this->config['context']) {
            throw new RuntimeException('root_id должен указывать на существующую msCategory в выбранном контексте');
        }
        $this->config['category_template'] ??= (int)$root->get('template');
        $this->config['product_template'] ??= (int)$this->modx->getOption('ms3_template_product_default', null, $root->get('template'));
        // Identity is stored in resource properties, independently of MODX alias settings.
        foreach ($this->modx->getIterator(modResource::class, [
            'context_key' => $this->config['context'], 'class_key' => msCategory::class,
        ]) as $resource) {
            $meta = ((array)$resource->get('properties'))['memorialsxml'] ?? [];
            if (($meta['source'] ?? '') !== $this->config['source_key'] || !isset($meta['category_id'])) { continue; }
            $externalId = (string)$meta['category_id'];
            if (isset($this->categoryExisting[$externalId])) { throw new RuntimeException('Повторная привязка категории: ' . $externalId); }
            $this->categoryExisting[$externalId] = $resource;
        }
        foreach (array_keys($categories) as $id) { $this->category((string)$id, $categories); }
        $count = 0;
        $position = 0;
        try {
            foreach ($this->feed->nodes($path, 'offer') as $node) {
                $o = $this->feed->offer($node);
                $article = $o['data']['article'];
                if ($this->feed->isDuplicate($article)) {
                    $this->stats['skipped_duplicates']++;
                    self::log('duplicate_skipped', ['article' => $article, 'offer_id' => $o['external_id'], 'name' => $o['name']]);
                    continue;
                }
                if ($position++ < $offset) { continue; }
                if (($limit > 0 && $count >= $limit) || ($deadline > 0 && microtime(true) >= $deadline)) { break; }
                $count++;
                $failure = null;
                self::log('product_start', ['article' => $article, 'offset' => $position - 1]);
                try {
                    $existing = $this->product($article);
                    $category = $this->categoryMap[$o['category']];
                    $pid = $existing ? (int)$existing->get('id') : 0;
                    $action = $existing ? 'updated' : 'created';
                    if (!$this->dryRun) {
                        $preserveZero = $o['data']['price'] === 0.0 && $this->config['zero_price_policy'] === 'preserve';
                        if ($preserveZero && $existing) { unset($o['data']['price']); }
                        $properties = $existing ? (array)$existing->get('properties') : [];
                        $properties['memorialsxml'] = [
                            'source' => $this->config['source_key'], 'offer_id' => $o['external_id'],
                            'updated_at' => date(DATE_ATOM),
                        ];
                        $payload = $existing ? $this->existingPayload($existing) : [
                            'template' => $this->config['product_template'],
                            'published' => (int)($this->config['publish_new_products'] && !$preserveZero),
                            'hidemenu' => 1, 'show_in_tree' => 0,
                            'alias' => 'memorials-product-' . substr(hash('sha256', $this->config['source_key'] . ':' . $article), 0, 24),
                        ];
                        $payload = array_replace($payload, [
                            'pagetitle' => $o['name'], 'parent' => $category,
                            'context_key' => $this->config['context'], 'class_key' => msProduct::class,
                            'content' => $o['content'], 'richtext' => 0,
                            'Data' => $o['data'], 'properties' => $properties,
                        ]);
                        if ($existing) { $payload['id'] = $pid; }
                        $saved = $this->process('MiniShop3\\Processors\\Product\\' . ($existing ? 'Update' : 'Create'), $payload);
                        $pid = (int)($saved['id'] ?? 0);
                        $data = $pid > 0 ? $this->modx->getObject(msProductData::class, $pid) : null;
                        if (!$data || (string)$data->get('article') !== $article) {
                            throw new RuntimeException('Не удалось проверить сохранение товара/артикула');
                        }
                        foreach ($o['data'] as $key => $value) {
                            if ($key !== 'article' && abs((float)$data->get($key) - $value) > 0.0001) {
                                throw new RuntimeException('Не сохранилось поле: ' . $key);
                            }
                        }
                        $this->options($pid, $category, $o['options']);
                        if ($this->config['images']) { $this->images($pid, $o['pictures']); }
                    }
                    $this->stats[$action]++;
                    self::log('product', ['article' => $article, 'id' => $pid, 'action' => $action, 'dry_run' => $this->dryRun]);
                } catch (Throwable $e) {
                    $failure = $e->getMessage();
                    $this->stats['failed']++;
                    self::log('product_error', ['article' => $article, 'error' => $e->getMessage()]);
                }
                $this->stats['next_offset'] = $position;
                if ($checkpoint) {
                    $checkpoint(['next_offset' => $position, 'article' => $article, 'error' => $failure]);
                }
            }
        } finally {
            if (!$this->dryRun) {
                $this->modx->cacheManager->refresh(['resource' => ['contexts' => [$this->config['context']]]]);
            }
        }
        self::log('summary', $this->stats + ['dry_run' => $this->dryRun]);
        return $this->stats;
    }
}
