<?php
declare(strict_types=1);
// In-memory API doubles: verifies importer decisions, NOT an integration test of MODX.
namespace MODX\Revolution {
    class modResource {
        public function __construct(public array $fields = []) {}
        public function get($key) { return $this->fields[$key] ?? null; }
        public function set($key, $value): void { $this->fields[$key] = $value; }
        public function toArray(): array { return $this->fields; }
    }
    class Query {
        public array $where = [];
        public function innerJoin(...$args): void {}
        public function where($where): void { $this->where = $where; }
        public function limit(...$args): void {}
    }
    class Response {
        public function __construct(private array $object) {}
        public function isError(): bool { return false; }
        public function getObject(): array { return $this->object; }
    }
    class Statement {
        private int $value = 0;
        public function __construct(private modX $modx, private string $sql) {}
        public function execute($parameters): bool {
            if (str_contains($this->sql, 'GET_LOCK')) {
                $this->value = $this->modx->dbLocked ? 0 : 1;
                if ($this->value) { $this->modx->dbLocked = true; }
            } else { $this->modx->dbLocked = false; $this->value = 1; }
            return true;
        }
        public function fetchColumn(): int { return $this->value; }
    }
    class Cache { public function refresh(...$args): void {} }
    class modX {
        public array $resources = [], $data = [], $options = [], $definitions = [], $bindings = [], $calls = [];
        public Cache $cacheManager;
        public bool $dbLocked = false;
        public function prepare($sql): Statement { return new Statement($this, $sql); }
        public function __construct() { $this->cacheManager = new Cache(); }
        public function getFields($class): array { return array_fill_keys(['id', 'pagetitle', 'alias', 'parent', 'properties', 'template', 'published', 'hidemenu', 'show_in_tree', 'context_key', 'class_key', 'content', 'richtext', 'deleted'], null); }
        public function getOption($name, $options = null, $default = null) { return $default; }
        public function newQuery($class): Query { return new Query(); }
        public function getCollection($class, $query): array {
            if (!$query instanceof Query) { throw new \RuntimeException('Unexpected query'); }
            $matches = [];
            foreach ($this->resources as $id => $r) {
                if ($r instanceof \MiniShop3\Model\msProduct && ($this->data[$id]->get('article') ?? null) === $query->where['ImportData.article'] && $r->get('context_key') === $query->where['msProduct.context_key']) { $matches[] = $r; }
            }
            return $matches;
        }
        public function getIterator($class, $where): array {
            return array_filter($this->resources, fn($r) => $r instanceof \MiniShop3\Model\msCategory && $r->get('context_key') === $where['context_key']);
        }
        public function getObject($class, $where) {
            if ($class === \MiniShop3\Model\msProductData::class) { return $this->data[$where] ?? null; }
            if (is_int($where)) { return $this->resources[$where] ?? null; }
            if ($class === \MiniShop3\Model\msOption::class) { return $this->definitions[$where['key']] ?? null; }
            if ($class === \MiniShop3\Model\msCategoryOption::class) { return $this->bindings[$where['category_id'] . ':' . $where['option_id']] ?? null; }
            return null;
        }
        public function newObject($class) { return new $class([], $this); }
        public function runProcessor($class, $payload): Response {
            $this->calls[] = [$class, $payload];
            $id = $payload['id'] ?? (count($this->resources) + 1);
            $model = str_contains($class, '\\Product\\') ? \MiniShop3\Model\msProduct::class : \MiniShop3\Model\msCategory::class;
            $old = $this->resources[$id] ?? new $model();
            $resource = $payload; unset($resource['Data']);
            $resource['id'] = $id;
            $old->fields = array_replace($old->fields, $resource);
            $this->resources[$id] = $old;
            if (isset($payload['Data'])) {
                $this->data[$id] ??= new \MiniShop3\Model\msProductData();
                $this->data[$id]->fields = array_replace($this->data[$id]->fields, $payload['Data']);
            }
            return new Response(['id' => $id]);
        }
    }
}
namespace MiniShop3\Model {
    class msProduct extends \MODX\Revolution\modResource {}
    class msCategory extends \MODX\Revolution\modResource {}
    class msProductData extends \MODX\Revolution\modResource {}
    class msOption extends \MODX\Revolution\modResource {
        public function __construct(array $fields = [], protected ?\MODX\Revolution\modX $modx = null) { parent::__construct($fields); }
        public function fromArray($fields): void { $this->fields = $fields; }
        public function save(): bool {
            $this->fields['id'] = count($this->modx->definitions) + 1;
            $this->modx->definitions[$this->fields['key']] = $this;
            return true;
        }
    }
    class msCategoryOption extends msOption {
        // Match xPDO: fromArray() skips composite primary keys by default.
        public function fromArray($fields, $prefix = '', $setPrimaryKeys = false): void {
            foreach ($fields as $key => $value) {
                if (!$setPrimaryKeys && in_array($key, ['option_id', 'category_id'], true)) { continue; }
                $this->set($key, $value);
            }
        }
        public function save(): bool {
            if ((int)$this->get('category_id') < 1 || (int)$this->get('option_id') < 1) { return false; }
            $this->modx->bindings[$this->get('category_id') . ':' . $this->get('option_id')] = $this;
            return true;
        }
    }
    class msProductOption extends msOption {
        public function saveProductOptions($id, $values, $removeOther): bool {
            if ($removeOther) { throw new \RuntimeException('Unrelated options must survive'); }
            $this->modx->options[$id] = array_replace($this->modx->options[$id] ?? [], $values); return true;
        }
        public function getForProduct($id, $keys): array { return array_intersect_key($this->modx->options[$id] ?? [], array_flip($keys)); }
    }
}
namespace {
    require dirname(__DIR__) . '/src/Feed.php';
    require dirname(__DIR__) . '/src/Downloader.php';
    require dirname(__DIR__) . '/src/Importer.php';
    require dirname(__DIR__) . '/src/ConsoleBatch.php';
    use MemorialsXml\{Feed, Downloader, Importer, ConsoleBatch};
    class TestConsoleBatch extends ConsoleBatch {
        public static array $uploads = [];
        public static bool $failUploads = true;
        public static bool $detailedFailure = false;
        protected function uploadImage(Importer $importer, int $productId, string $url): array {
            self::$uploads[] = $url;
            if (self::$detailedFailure) { return ['images_added' => 0, 'images_existing' => 0, 'image_errors' => 1, 'errors' => [['error' => 'HTTP 404: source image missing']]]; }
            if (self::$failUploads && str_ends_with($url, '/fail.png')) { throw new \RuntimeException('Simulated image failure'); }
            return ['images_added' => 1, 'images_existing' => 0, 'image_errors' => 0];
        }
    }
    use MiniShop3\Model\{msCategory, msProduct};
    function ensure(bool $ok, string $message): void { if (!$ok) { throw new \RuntimeException($message); } }
    $config = require dirname(__DIR__) . '/config.example.php';
    $config['root_id'] = 1; $config['images'] = false;
    $modx = new \MODX\Revolution\modX();
    $modx->resources[1] = new msCategory(['id' => 1, 'context_key' => 'web', 'template' => 7]);
    $temp = tempnam(sys_get_temp_dir(), 'memorials-smoke-');
    $xml = '<yml_catalog><shop><categories><category id="a">Root</category><category id="b" parentId="a">Child</category></categories><offers><offer available="false"><name>Test</name><categoryId>b</categoryId><price>%s</price><currencyId>RUB</currencyId><param name="Артикул">001</param><param name="Мощность">10</param></offer></offers></shop></yml_catalog>';
    $run = function (bool $dry = false) use ($config, $modx, $temp): array {
        $feed = new Feed($config);
        $feed->inspect($temp);
        return (new Importer($modx, $config, $feed, new Downloader($config), dirname($temp), $dry))->run($temp);
    };
    try {
        file_put_contents($temp, sprintf($xml, '100'));
        $run(true);
        ensure(count($modx->resources) === 1 && !$modx->calls, 'Dry run mutated resources');
        $stats = $run();
        ensure($stats['created'] === 1 && count($modx->resources) === 4, 'First import did not create category tree and product');
        ensure(count($modx->bindings) === 2, 'Category options were not bound');
        foreach ($modx->bindings as $binding) {
            ensure((int)$binding->get('category_id') === 3 && (int)$binding->get('option_id') > 0 && (int)$binding->get('active') === 1, 'Composite category-option primary keys lost');
        }
        $product = $modx->resources[4];
        ensure($product instanceof msProduct && $product->get('parent') === 3, 'Wrong product parent');
        ensure($modx->resources[3]->get('parent') === 2, 'Wrong category hierarchy');
        $bindingCount = count($modx->bindings);
        // Simulate edits in MODX: hidden product, custom alias, unrelated option.
        $product->set('published', 0); $product->set('template', 99);
        $modx->resources[2]->set('alias', 'custom-alias');
        $modx->options[4]['custom'] = ['keep'];
        file_put_contents($temp, sprintf($xml, '200'));
        $stats = $run();
        ensure($stats['updated'] === 1 && count($modx->resources) === 4, 'Repeat import created duplicates');
        ensure(count($modx->bindings) === $bindingCount, 'Repeat import duplicated category-option links');
        ensure($modx->data[4]->get('price') === 200.0, 'Price was not updated');
        ensure($product->get('published') === 0 && $product->get('template') === 99, 'Publication/template changed on update');
        ensure($modx->resources[2]->get('alias') === 'custom-alias', 'Category identity depends on alias');
        ensure($modx->options[4]['custom'] === ['keep'], 'Unrelated options removed');
        ensure($modx->options[4]['memorials_available'] === ['0'], 'False availability lost');
        file_put_contents($temp, sprintf($xml, '0'));
        $run();
        ensure($modx->data[4]->get('price') === 200.0, 'Zero price replaced known price');
        // A new zero-priced product must be unpublished.
        file_put_contents($temp, str_replace('>001<', '>002<', sprintf($xml, '0')));
        $run();
        ensure($modx->resources[5]->get('published') === 0, 'New zero-priced product is published');
        // Both repeated SKU records must be skipped, even if an existing product matches.
        $beforeCount = count($modx->resources);
        file_put_contents($temp, str_replace('</offers>', '<offer id="dup" available="true"><name>Conflicting</name><categoryId>b</categoryId><price>999</price><currencyId>RUR</currencyId><vendorCode>001</vendorCode></offer></offers>', sprintf($xml, '777')));
        $stats = $run();
        ensure($stats['skipped_duplicates'] === 2 && $stats['created'] === 0 && $stats['updated'] === 0, 'Duplicate records were processed');
        ensure(count($modx->resources) === $beforeCount && $modx->data[4]->get('price') === 200.0, 'Duplicate SKU changed existing product');
        $modx->data[4]->set('stock', 25.0);
        file_put_contents($temp, str_replace('</offer>', '<weight>84</weight></offer>', sprintf($xml, '300')));
        $stats = $run();
        ensure($modx->data[4]->get('weight') === 84.0 && $modx->data[4]->get('stock') === 25.0, 'Weight or missing-stock mapping failed');
        $imageProbe = new Importer($modx, $config, new Feed($config), new Downloader($config), dirname($temp), false);
        $imageResult = $imageProbe->importImages(4, ['https://kukmorstone.ru/unsupported.svg']);
        ensure($imageResult['image_errors'] === 1 && count($imageResult['errors']) === 1 && str_contains($imageResult['errors'][0]['error'], 'расширение'), 'Image failure detail not returned');
        $imageResult = $imageProbe->importImages(4, ['https://kukmorstone.ru/another.txt']);
        ensure(count($imageResult['errors']) === 1 && $imageResult['errors'][0]['url'] === 'https://kukmorstone.ru/another.txt', 'Image failure result leaked previous call errors');
        $batchDir = sys_get_temp_dir() . '/memorials-batch-test-' . bin2hex(random_bytes(8));
        $batchModx = new \MODX\Revolution\modX();
        $batchModx->resources[1] = new msCategory(['id' => 1, 'context_key' => 'web', 'template' => 7]);
        $batchConfig = $config; $batchConfig['source'] = $temp; $batchConfig['images'] = true;
        $batchXml = '<yml_catalog><shop><categories><category id="a">A</category><category id="b" parentId="a">B</category></categories><offers>';
        for ($i = 1; $i <= 3; $i++) {
            $picture2 = $i === 3 ? 'https://kukmorstone.ru/fail.png' : 'https://kukmorstone.ru/' . $i . '-2.png';
            $batchXml .= '<offer><name>Batch ' . $i . '</name><vendorCode>020' . $i . '</vendorCode><categoryId>b</categoryId><price>100</price><currencyId>RUR</currencyId><picture>https://kukmorstone.ru/' . $i . '-1.png</picture><picture>' . $picture2 . '</picture></offer>';
        }
        $batchXml .= '</offers></shop></yml_catalog>';
        file_put_contents($temp, $batchXml);
        $batchRun = static fn(bool $reset = false): array => (new TestConsoleBatch($batchModx, $batchConfig, $batchDir))->run(1, 60, $reset);
        try {
            $state = $batchRun();
            ensure($state['next_offset'] === 1 && $state['phase'] === 'products', 'Batch did not stop after first product');
            $beforeCalls = count($batchModx->calls);
            // Live feed changes must not change the snapshot being processed.
            file_put_contents($temp, str_replace('0202', '9999', $batchXml));
            $state = $batchRun();
            ensure($state['next_offset'] === 2, 'Batch restarted instead of resuming');
            ensure(count($batchModx->calls) === $beforeCalls + 1, 'Unchanged categories were saved again');
            $state = $batchRun();
            ensure($state['phase'] === 'images' && $state['next_offset'] === 3 && !TestConsoleBatch::$uploads, 'Products/images phases were mixed');
            $state = $batchRun();
            ensure($state['images_processed'] === 1 && count(TestConsoleBatch::$uploads) === 1, 'Image batch limit ignored');
            $state = $batchRun();
            ensure($state['images_processed'] === 2 && $state['images_added'] === 2, 'Image cursor not resumed');
            // Simulate two requests being killed inside the third image upload.
            $state['pending_image'] = ['product' => 1, 'index' => 0, 'attempts' => 2];
            file_put_contents($batchDir . '/state.json', json_encode($state));
            $state = $batchRun();
            ensure($state['images_processed'] === 3 && count($state['failed_images']) === 1 && count(TestConsoleBatch::$uploads) === 2, 'Interrupted image retried forever');
            for ($i = 0; $i < 5 && !str_starts_with($state['phase'], 'done'); $i++) { $state = $batchRun(); }
            ensure($state['phase'] === 'done_with_errors' && $state['images_processed'] === 6 && count($state['failed_images']) === 2, 'Image errors or completion lost');
            $beforeCalls = count($batchModx->calls);
            $batchRun();
            ensure(count($batchModx->calls) === $beforeCalls, 'Completed run restarted automatically');
            // Targeted retries must neither reimport products nor redownload the live feed.
            $retryRun = static fn(): array => (new TestConsoleBatch($batchModx, $batchConfig, $batchDir))->run(1, 60, false, true);
            TestConsoleBatch::$failUploads = false;
            TestConsoleBatch::$detailedFailure = true;
            $state = $retryRun();
            ensure($state['phase'] === 'retry_images' && $state['retry_index'] === 1 && $state['retry_total'] === 2, 'Retry batch limit ignored');
            ensure($state['failed_images'][0]['error'] === 'HTTP 404: source image missing', 'Detailed image failure was lost');
            TestConsoleBatch::$detailedFailure = false;
            $state = $retryRun();
            ensure($state['phase'] === 'done_with_errors' && count($state['failed_images']) === 1, 'Retry did not preserve unresolved error');
            // A new manual retry cycle processes only the remaining failed URL.
            $state = $retryRun();
            ensure($state['phase'] === 'done' && !$state['failed_images'] && $state['retry_total'] === 1, 'Recovered image remains in failed queue');
            ensure($state['images_processed'] === 6 && $state['next_offset'] === 3 && count($batchModx->calls) === $beforeCalls, 'Retry reset the import or reimported products');
            $uploadsBefore = count(TestConsoleBatch::$uploads);
            $retryRun();
            ensure(count(TestConsoleBatch::$uploads) === $uploadsBefore, 'Empty retry queue restarted automatically');
            $batchModx->dbLocked = true;
            $blocked = false;
            try { $batchRun(); } catch (\RuntimeException $e) { $blocked = true; }
            ensure($blocked, 'Parallel import lock ignored');
            $batchModx->dbLocked = false;
            file_put_contents($temp, $batchXml);
            $state = $batchRun(true);
            ensure($state['next_offset'] === 1 && $state['phase'] === 'products' && $state['images_processed'] === 0, 'Explicit reset failed');
            $onlyDir = $batchDir . '-images';
            $onlyConfig = $batchConfig; $onlyConfig['images_only'] = true;
            unset($batchModx->resources[6], $batchModx->data[6]); // Third product does not exist.
            $mainStateBefore = file_get_contents($batchDir . '/state.json');
            $callsBefore = count($batchModx->calls);
            $uploadsBefore = count(TestConsoleBatch::$uploads);
            try {
                $onlyRun = static fn(): array => (new TestConsoleBatch($batchModx, $onlyConfig, $onlyDir))->run(1, 60);
                $onlyState = $onlyRun();
                ensure($onlyState['phase'] === 'images' && $onlyState['images_processed'] === 1 && $onlyState['next_offset'] === 0, 'Images-only mode ran product phase');
                for ($i = 0; $i < 10 && !str_starts_with($onlyState['phase'], 'done'); $i++) { $onlyState = $onlyRun(); }
                ensure($onlyState['phase'] === 'done' && $onlyState['missing_products'] === ['0203'] && $onlyState['missing_product_images'] === 2, 'Missing product was not skipped and reported');
                ensure($onlyState['images_added'] === 4 && count(TestConsoleBatch::$uploads) === $uploadsBefore + 4, 'Wrong image-only upload count');
                ensure(count($batchModx->calls) === $callsBefore && !isset($batchModx->resources[6]), 'Images-only mode created/updated products');
                ensure(file_get_contents($batchDir . '/state.json') === $mainStateBefore, 'Images-only mode changed main import cursor');
                $onlyRun();
                ensure(count(TestConsoleBatch::$uploads) === $uploadsBefore + 4, 'Completed images-only queue restarted');
            } finally {
                foreach (glob($onlyDir . '/*') ?: [] as $file) { unlink($file); }
                if (is_dir($onlyDir)) { rmdir($onlyDir); }
            }

        } finally {
            foreach (glob($batchDir . '/*') ?: [] as $file) { unlink($file); }
            if (is_dir($batchDir)) { rmdir($batchDir); }
        }
        echo "OK: persistent Console batches (product/image cursors, snapshot, limits, unchanged categories, interruption, errors, lock, reset)\n";
        echo "OK: importer smoke (dry-run, hierarchy, repeat import, price update, zero price, preservation)\n";
    } finally { unlink($temp); }
}
