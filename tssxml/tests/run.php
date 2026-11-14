<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Feed.php';
require dirname(__DIR__) . '/src/Downloader.php';

use TssXml\{Feed, Downloader};

$config = require dirname(__DIR__) . '/config.example.php';
$config['duplicate_policy'] = 'error';
$feed = new Feed($config);
$total = 0;
function check(bool $ok, string $name): void {
    global $total;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $total++;
    echo "PASS: $name\n";
}
function throws(callable $fn, string $name): void {
    try { $fn(); } catch (RuntimeException $e) { check(true, $name); return; }
    check(false, $name);
}
function document(string $offers, string $categories = '<category id="1">Категория</category>'): string {
    return '<?xml version="1.0"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog><shop><categories>'
        . $categories . '</categories><offers>' . $offers . '</offers></shop></yml_catalog>';
}
function offer(string $sku = '00123', string $extra = '', string $price = '10,50', string $currency = 'RUB'): string {
    return '<offer id="1" available="false"><categoryId>1</categoryId><name>Товар</name><price>' . $price
        . '</price><currencyId>' . $currency . '</currencyId><param name="Артикул">' . $sku . '</param>' . $extra . '</offer>';
}
$base = sys_get_temp_dir() . '/tssxml-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$path = $base . '/test.xml';
try {
    $o = $feed->offer(simplexml_load_string(offer('00123', '<param name="Размер" unit="мм">10</param><param name="Размер" unit="мм">20</param>')));
    check($o['data']['article'] === '00123', 'Артикул с ведущими нулями');
    check($o['data']['price'] === 10.5, 'Цена с десятичной запятой');
    check(!array_key_exists('stock', $o['data']), 'available не превращается в числовой остаток');
    check($o['options']['tss_available']['values'] === ['0'], 'available=false сохраняется');
    $key = Feed::optionKey('Размер');
    check($o['options'][$key]['values'] === ['10', '20'] && $o['options'][$key]['unit'] === 'мм', 'Повторные параметры и единицы измерения');
    check(Feed::optionKey('Размер') === $key && Feed::optionKey('размер') !== $key, 'Постоянные ключи характеристик');
    $o = $feed->offer(simplexml_load_string(offer('00123', '<vendorCode>000456</vendorCode><weight>84</weight><barcode>001234567890</barcode><dimensions>100/50/6</dimensions>', '17100', 'RUR')));
    check($o['data']['article'] === '000456', 'Артикул из vendorCode имеет приоритет');
    check($o['data']['price'] === 17100.0, 'RUR принимается как RUB без конвертации');
    check($o['data']['weight'] === 84.0, 'Вес из weight сохраняется в поле weight');
    check($o['options']['tss_barcode']['values'] === ['001234567890'], 'Штрихкод сохраняет ведущие нули');
    check($o['options']['tss_dimensions']['values'] === ['100/50/6'], 'Габариты сохраняются в сантиметрах');
    $o = $feed->offer(simplexml_load_string(offer('001', '<param name="Вес, кг">12,5</param>')));
    check($o['data']['weight'] === 12.5, 'Вес из param при отсутствии weight');
    $o = $feed->offer(simplexml_load_string(offer('001', '<weight>0</weight><param name="Вес, кг">12</param>')));
    check($o['data']['weight'] === 0.0, 'Явный нулевой weight имеет приоритет');
    $o = $feed->offer(simplexml_load_string(offer('001', '<quantity>0</quantity>')));
    check($o['data']['stock'] === 0.0, 'Явный нулевой остаток');
    $o = $feed->offer(simplexml_load_string(offer('001', '<param name="Остаток">1 234,125</param>')));
    check($o['data']['stock'] === 1234.125, 'Числовой остаток из param');
    $o = $feed->offer(simplexml_load_string(offer('001', '<description>Коротко</description><param name="Описание товара">&lt;p&gt;Подробно&lt;/p&gt; [[!snippet]] &lt;script&gt;bad&lt;/script&gt;</param>')));
    check(str_contains($o['content'], 'Подробно') && !str_contains($o['content'], 'Коротко') && !str_contains($o['content'], '[[') && !str_contains($o['content'], '<script'), 'Подробное описание и нейтрализация HTML/MODX тегов');
    $o = $feed->offer(simplexml_load_string(offer('001', '', '0', '')));
    check($o['data']['price'] === 0.0, 'Нулевая цена с пустой currencyId с пустой валютой');
    throws(fn() => $feed->offer(simplexml_load_string(offer('001', '', '10', ''))), 'Пустая валюта при положительной цене отклоняется');
    throws(fn() => $feed->offer(simplexml_load_string(offer('001', '', '10', 'USD'))), 'Чужая валюта отклоняется');
    throws(fn() => $feed->offer(simplexml_load_string(offer(''))), 'Пустой артикул отклоняется');
    throws(fn() => Feed::number('-1', 1000, 2), 'Отрицательная цена отклоняется');
    throws(fn() => Feed::number('NaN', 1000, 2), 'Нечисловая цена отклоняется');
    throws(fn() => Feed::number('1001', 1000, 2), 'Переполнение поля отклоняется');
    file_put_contents($path, document(offer()));
    $report = $feed->inspect($path);
    check($report['offers'] === 1 && $report['categories'] === 1, 'YML с внешним DTD читается без shops.dtd');
    file_put_contents($path, document(offer() . offer()));
    throws(fn() => $feed->inspect($path), 'Дубликаты артикулов отклоняются до импорта');
    $skipConfig = $config; $skipConfig['duplicate_policy'] = 'skip';
    $skipFeed = new Feed($skipConfig);
    file_put_contents($path, document(offer('0001') . str_replace('id="1"', 'id="2"', offer('0001')) . offer('0002')));
    $report = $skipFeed->inspect($path);
    check($report['duplicate_records'] === 2 && $report['importable_offers'] === 1, 'Все записи конфликтного артикула исключены из плана');
    check($skipFeed->isDuplicate('0001') && !$skipFeed->isDuplicate('0002'), 'Сопоставление конфликтных артикулов');
    file_put_contents($path, document(offer('Abc') . offer('abc')));
    $report = $skipFeed->inspect($path);
    check($report['duplicate_records'] === 2 && $skipFeed->isDuplicate('ABC'), 'Дубликаты проверяются без учёта регистра');
    file_put_contents($path, document(str_replace('<categoryId>1', '<categoryId>2', offer())));
    throws(fn() => $feed->inspect($path), 'Неизвестная категория отклоняется');
    file_put_contents($path, document(offer(), '<category id="1" parentId="2">A</category><category id="2" parentId="1">B</category>'));
    throws(fn() => $feed->inspect($path), 'Циклы категорий отклоняются');
    file_put_contents($path, document(offer(), '<category id="1" parentId="99">A</category>'));
    throws(fn() => $feed->inspect($path), 'Отсутствующий родитель категории отклоняется');
    file_put_contents($path, document(offer()) . '<broken>');
    throws(fn() => $feed->inspect($path), 'Поврежденный XML отклоняется до записи');
    file_put_contents($path, '<!DOCTYPE yml_catalog [<!ENTITY evil SYSTEM "file:///etc/passwd">]><yml_catalog><shop><categories><category id="1">&evil;</category></categories><offers>' . offer() . '</offers></shop></yml_catalog>');
    throws(fn() => $feed->inspect($path), 'Внутренние DTD/XXE отклоняются');
    $downloader = new Downloader($config);
    $downloader->checkUrl('https://tss.ru/a.jpg');
    check(true, 'Разрешенный домен');
    throws(fn() => $downloader->checkUrl('https://evil.test/a.jpg'), 'Чужой домен отклоняется');
    throws(fn() => $downloader->checkUrl('file:///etc/passwd'), 'URL file:// отклоняется');
    throws(fn() => $downloader->checkUrl('https://u:p@tss.ru/a'), 'URL с учетными данными отклоняется');
    throws(fn() => $downloader->checkUrl('https://tss.ru:123/a'), 'Неожиданный порт отклоняется');
    file_put_contents($path, document(offer()));
    $copy = $base . '/snapshot'; mkdir($copy);
    check(is_file($downloader->snapshot($path, $copy)), 'Снимок локального файла');
    $small = $config; $small['max_feed_bytes'] = 10;
    throws(fn() => (new Downloader($small))->snapshot($path, $copy), 'Лимит размера локального XML');
    $fixtureFeed = new Feed($skipConfig);
    $fixture = __DIR__ . '/fixtures/tss-sample.xml';
    $fixtureReport = $fixtureFeed->inspect($fixture);
    check($fixtureReport['offers'] === 3 && $fixtureReport['duplicate_records'] === 2 && $fixtureReport['importable_offers'] === 1, 'Образец реального tss.xml: корректный план с конфликтами');
    foreach ($fixtureFeed->nodes($fixture, 'offer') as $node) {
        $sample = $fixtureFeed->offer($node);
        check($sample['data']['article'] === '020172' && $sample['data']['price'] === 3882.24, 'Образец ТСС: артикул и цена');
        break;
    }
    echo "OK: $total tests\n";
} finally {
    foreach (glob($base . '/snapshot/*') ?: [] as $f) { unlink($f); }
    if (is_dir($base . '/snapshot')) { rmdir($base . '/snapshot'); }
    foreach (glob($base . '/*') ?: [] as $f) { unlink($f); }
    rmdir($base);
}
