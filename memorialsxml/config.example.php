<?php
return [
    // Абсолютный путь к корню MODX, где находятся config.core.php и index.php.
    'modx_base_path' => '/var/www/example.ru/',
    'source' => 'https://kukmorstone.ru/bitrix/catalog_export/memorials.xml',
    // Или: 'source' => '/var/import/memorials.xml',
    'source_key' => 'kukmorstone_memorials', // Постоянный ID поставщика: не менять между запусками.
    'context' => 'web',
    'root_id' => 0, // ОБЯЗАТЕЛЬНО: ID существующей корневой категории miniShop3.
    'manager_user_id' => 1, // Активный пользователь MODX с sudo=true, только для CLI.
    'product_template' => null, // null = системный ms3_template_product_default.
    'category_template' => null, // null = шаблон корневой категории.
    'publish_new_products' => true,
    'publish_new_categories' => true,
    'images' => true,
    // Разрешённые домены для XML и изображений; редиректы намеренно запрещены.
    'allowed_hosts' => ['kukmorstone.ru', 'www.kukmorstone.ru'],
    'http_timeout' => 180,
    // null = стандартный curl/<версия>; сайт отклоняет нестандартный User-Agent.
    'http_user_agent' => null,
    'max_feed_bytes' => 256 * 1024 * 1024,
    'max_image_bytes' => 20 * 1024 * 1024,
    'max_image_pixels' => 40000000,
    // Числовой остаток: сначала теги, затем param с перечисленными именами.
    'stock_tags' => ['quantity', 'stock', 'count'],
    'stock_params' => ['Остаток', 'Остаток на складе', 'Количество'],
    'currency' => 'RUB',
    'currency_aliases' => ['RUR' => 'RUB'],
    // skip: пропустить ВСЕ записи с повторным артикулом; error: остановить до записи.
    'duplicate_policy' => 'skip',
    // preserve: нулевая цена не заменяет существующую; новые без цены не публикуются.
    // import: переносить 0 как обычную цену, публикация по publish_new_products.
    'zero_price_policy' => 'preserve',
];
