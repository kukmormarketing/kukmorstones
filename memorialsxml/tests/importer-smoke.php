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
    class Cache { public function refresh(...$args): void {} }
    class modX {
        public array $resources = [], $data = [], $options = [], $definitions = [], $bindings = [], $calls = [];
        public Cache $cacheManager;
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
        public function save(): bool { $this->modx->bindings[$this->get('category_id') . ':' . $this->get('option_id')] = $this; return true; }
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
    use MemorialsXml\{Feed, Downloader, Importer};
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
        $product = $modx->resources[4];
        ensure($product instanceof msProduct && $product->get('parent') === 3, 'Wrong product parent');
        ensure($modx->resources[3]->get('parent') === 2, 'Wrong category hierarchy');
        // Simulate edits in MODX: hidden product, custom alias, unrelated option.
        $product->set('published', 0); $product->set('template', 99);
        $modx->resources[2]->set('alias', 'custom-alias');
        $modx->options[4]['custom'] = ['keep'];
        file_put_contents($temp, sprintf($xml, '200'));
        $stats = $run();
        ensure($stats['updated'] === 1 && count($modx->resources) === 4, 'Repeat import created duplicates');
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
        echo "OK: importer smoke (dry-run, hierarchy, repeat import, price update, zero price, preservation)\n";
    } finally { unlink($temp); }
}
