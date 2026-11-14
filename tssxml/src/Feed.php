<?php
declare(strict_types=1);
namespace TssXml;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

final class Feed
{
    private array $duplicates = [];
    private ?string $validatedPath = null;
    public function __construct(private array $config) {}

    /** Streaming read. External DTDs are not loaded, entities are not expanded. */
    public function nodes(string $path, string $tag): Generator
    {
        $before = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new XMLReader();
        try {
            if (!$r->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Не удалось открыть XML');
            }
            $r->setParserProperty(XMLReader::LOADDTD, false);
            $r->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $r->setParserProperty(XMLReader::VALIDATE, false);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::DOC_TYPE && str_contains($r->value, '[')) {
                    throw new RuntimeException('Внутренний DTD/объявления ENTITY запрещены');
                }
                if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== $tag) {
                    continue;
                }
                // Expected YML path; ignore similarly named tags inside descriptions/extensions.
                $depth = $tag === 'category' || $tag === 'offer' ? 3 : -1;
                if ($r->depth !== $depth) {
                    continue;
                }
                $xml = simplexml_load_string($r->readOuterXml(), SimpleXMLElement::class, LIBXML_NONET);
                if ($xml === false) {
                    throw new RuntimeException('Ошибка XML в ' . $tag);
                }
                yield $xml;
            }
            foreach (libxml_get_errors() as $e) {
                if ($e->level >= LIBXML_ERR_ERROR) {
                    throw new RuntimeException('Ошибка XML, строка ' . $e->line . ': ' . trim($e->message));
                }
            }
        } finally {
            $r->close();
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
    }

    public function categories(string $path): array
    {
        $out = [];
        foreach ($this->nodes($path, 'category') as $node) {
            $id = trim((string)$node['id']);
            $name = trim((string)$node);
            if ($id === '' || $name === '' || mb_strlen($name) > 255 || isset($out[$id])) {
                throw new RuntimeException('Пустая/повторная категория: ' . $id);
            }
            $out[$id] = ['name' => $name, 'parent' => trim((string)$node['parentId'])];
        }
        $done = [];
        $visit = function (string $id, array $stack = []) use (&$visit, &$done, $out): void {
            if (isset($done[$id])) { return; }
            if (isset($stack[$id])) { throw new RuntimeException('Цикл категорий: ' . $id); }
            if (!isset($out[$id])) { throw new RuntimeException('Неизвестная категория: ' . $id); }
            $stack[$id] = true;
            $parent = $out[$id]['parent'];
            if ($parent !== '') { $visit($parent, $stack); }
            $done[$id] = true;
        };
        foreach (array_keys($out) as $id) { $visit((string)$id); }
        if (!$out) { throw new RuntimeException('В XML нет категорий YML'); }
        return $out;
    }

    public static function number(string $value, float $max, int $scale): float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new RuntimeException('Некорректное число: ' . substr($value, 0, 80));
        }
        $n = (float)$value;
        if (!is_finite($n) || $n > $max || round($n, $scale) > $max) {
            throw new RuntimeException('Число не помещается в поле miniShop3');
        }
        return round($n, $scale);
    }

    public static function optionKey(string $name): string
    {
        return 'tss_' . substr(hash('sha256', $name), 0, 24);
    }

    public function offer(SimpleXMLElement $xml): array
    {
        $params = [];
        $units = [];
        foreach ($xml->param as $p) {
            $name = trim((string)$p['name']);
            $value = trim((string)$p);
            if ($name !== '' && $value !== '') {
                $params[$name][] = $value;
                $units[$name] = trim((string)$p['unit']);
            }
        }
        // Preserve leading zeroes. The supplier's article is not its offer ID.
        $article = trim((string)$xml->vendorCode);
        if ($article === '') {
            $articles = array_values(array_unique($params['Артикул'] ?? []));
            if (count($articles) !== 1) { throw new RuntimeException('Нет единственного артикула'); }
            $article = $articles[0];
        }
        if ($article === '' || mb_strlen($article) > 50) {
            throw new RuntimeException('Пустой/слишком длинный артикул');
        }
        $name = trim((string)$xml->name);
        if ($name === '' || mb_strlen($name) > 255) { throw new RuntimeException('Пустое/длинное название: ' . $article); }
        $price = self::number((string)$xml->price, 9999999999.99, 2);
        $currency = trim((string)$xml->currencyId);
        $currency = $this->config['currency_aliases'][$currency] ?? $currency;
        // RUR and RUB denote the same ruble; no exchange-rate conversion.
        if ($currency !== $this->config['currency'] && !($currency === '' && $price === 0.0)) {
            throw new RuntimeException('Неподдерживаемая валюта: ' . $article);
        }
        $data = ['article' => $article, 'price' => $price];
        if (isset($xml->oldprice) && trim((string)$xml->oldprice) !== '') {
            $data['old_price'] = self::number((string)$xml->oldprice, 9999999999.99, 2);
        }
        foreach ($this->config['stock_tags'] as $tag) {
            if (isset($xml->{$tag}) && trim((string)$xml->{$tag}) !== '') {
                $data['stock'] = self::number((string)$xml->{$tag}, 9999999999.999, 3);
                break;
            }
        }
        if (!array_key_exists('stock', $data)) {
            foreach ($this->config['stock_params'] as $key) {
                if (isset($params[$key])) {
                    $data['stock'] = self::number($params[$key][0], 9999999999.999, 3);
                    break;
                }
            }
        }
        $weight = trim((string)$xml->weight);
        if ($weight === '') { $weight = $params['Вес, кг'][0] ?? ''; }
        if ($weight !== '') { $data['weight'] = self::number($weight, 9999999999.999, 3); }
        $description = $params['Описание товара'][0] ?? trim((string)$xml->description);
        // Treat supplier HTML/MODX tags as text. Templates must also escape option values.
        $description = preg_replace('~<(?:br\s*/?|/p|/div|/li)>~i', "\n", $description);
        $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        $content = str_replace(['[', ']'], ['&#91;', '&#93;'], $content);
        $options = [];
        foreach ($params as $key => $values) {
            if (in_array($key, ['Артикул', 'Описание товара', 'Старое описание товара (Старая версия)'], true)) {
                continue;
            }
            $options[self::optionKey($key)] = ['caption' => $key, 'unit' => $units[$key], 'values' => array_values(array_unique($values))];
        }
        foreach (['barcode' => ['tss_barcode', 'Штрихкод'], 'dimensions' => ['tss_dimensions', 'Габариты, см (Д/Ш/В)']] as $tag => [$key, $caption]) {
            $values = [];
            foreach ($xml->{$tag} as $value) {
                $text = trim((string)$value);
                if ($text !== '') { $values[] = $text; }
            }
            if ($values) {
                $options[$key] = ['caption' => $caption, 'unit' => '', 'values' => array_values(array_unique($values))];
            }
        }
        $available = strtolower(trim((string)$xml['available']));
        if ($available !== '' && !in_array($available, ['true', 'false', '1', '0'], true)) {
            throw new RuntimeException('Некорректный available: ' . $article);
        }
        if ($available !== '') {
            $options['tss_available'] = ['caption' => 'Наличие у поставщика', 'unit' => '', 'values' => [in_array($available, ['true', '1'], true) ? '1' : '0']];
        }
        $pictures = [];
        foreach ($xml->picture as $p) {
            $url = trim((string)$p);
            if ($url !== '') { $pictures[] = $url; }
        }
        return [
            'external_id' => trim((string)$xml['id']),
            'category' => trim((string)$xml->categoryId),
            'name' => $name, 'content' => $content, 'data' => $data,
            'options' => $options, 'pictures' => array_values(array_unique($pictures)),
        ];
    }

    public function isDuplicate(string $article): bool
    {
        return isset($this->duplicates[mb_strtolower($article)]);
    }

    public function ensureValidated(string $path): void
    {
        if ($this->validatedPath !== $path) { $this->inspect($path); }
    }

    /** Full validation before writes, bounded memory: only category/SKU sets retained. */
    public function inspect(string $path): array
    {
        $categories = $this->categories($path);
        $seen = [];
        $this->duplicates = [];
        $this->validatedPath = null;
        $count = $stock = $images = $zeroPrices = 0;
        foreach ($this->nodes($path, 'offer') as $node) {
            $o = $this->offer($node);
            $key = mb_strtolower($o['data']['article']);
            $seen[$key][] = ['article' => $o['data']['article'], 'offer_id' => $o['external_id'], 'name' => $o['name']];
            if (!isset($categories[$o['category']])) {
                throw new RuntimeException('Неизвестная categoryId: ' . $o['category']);
            }
            $count++;
            $zeroPrices += (int)($o['data']['price'] === 0.0);
            $stock += (int)array_key_exists('stock', $o['data']);
            $images += count($o['pictures']);
        }
        if ($count === 0) { throw new RuntimeException('В XML нет товаров YML'); }
        $duplicateRecords = 0;
        foreach ($seen as $key => $records) {
            if (count($records) > 1) {
                $this->duplicates[(string)$key] = $records;
                $duplicateRecords += count($records);
            }
        }
        if ($this->duplicates && $this->config['duplicate_policy'] === 'error') {
            throw new RuntimeException('Повторные артикулы: ' . implode(', ', array_map(static fn(array $records): string => $records[0]['article'], array_values($this->duplicates))));
        }
        $this->validatedPath = $path;
        return ['duplicate_articles' => array_values($this->duplicates), 'duplicate_records' => $duplicateRecords, 'importable_offers' => $count - $duplicateRecords, 'categories' => count($categories), 'offers' => $count, 'with_numeric_stock' => $stock, 'pictures' => $images, 'zero_prices' => $zeroPrices];
    }
}
