<?php
return [
    // Абсолютный путь к корню MODX, где находятся config.core.php и index.php.
    'modx_base_path' => '/var/www/example.ru/',
    'source' => 'https://www.tss.ru/bitrix/catalog_export/yandex_800463.xml',
    // Или: 'source' => '/var/import/tss.xml',
    'source_key' => 'tss', // Постоянный ID поставщика: не менять между запусками.
    'context' => 'web',
    'root_id' => 0, // ОБЯЗАТЕЛЬНО: ID существующей корневой категории miniShop3.
    'manager_user_id' => 1, // Активный пользователь MODX с sudo=true, только для CLI.
    'product_template' => null, // null = системный ms3_template_product_default.
    'category_template' => null, // null = шаблон корневой категории.
    'publish_new_products' => true,
    'publish_new_categories' => true,
    'images' => true,
    // Разрешённые домены для XML и изображений; редиректы намеренно запрещены.
    'allowed_hosts' => ['www.tss.ru', 'tss.ru'],
    'http_timeout' => 180,
    'max_feed_bytes' => 256 * 1024 * 1024,
    'max_image_bytes' => 20 * 1024 * 1024,
    'max_image_pixels' => 40000000,
    // Числовой остаток: сначала теги, затем param с перечисленными именами.
    'stock_tags' => ['quantity', 'stock', 'count'],
    'stock_params' => ['Остаток', 'Остаток на складе', 'Количество'],
    'currency' => 'RUB',
    'currency_aliases' => ['RUR' => 'RUB'],
    'duplicate_policy' => 'error',
    'http_user_agent' => null,
    // preserve: нулевая цена не заменяет существующую; новые без цены не публикуются.
    // import: переносить 0 как обычную цену, публикация по publish_new_products.
    'zero_price_policy' => 'preserve',
];
