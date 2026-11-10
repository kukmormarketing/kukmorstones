<?php
declare(strict_types=1);
// Optional contract test using the real xPDO class; no database connection needed.
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php tests/xpdo-primary-keys.php /path/to/xPDO/Om/xPDOObject.php\n");
    exit(1);
}
require_once $path;
function linkProbe(): \xPDO\Om\xPDOObject
{
    $object = (new ReflectionClass(\xPDO\Om\xPDOObject::class))->newInstanceWithoutConstructor();
    $object->_fields = ['option_id' => 0, 'category_id' => 0, 'active' => 0, 'required' => 0];
    foreach ($object->_fields as $key => $value) {
        $object->_fieldMeta[$key] = ['phptype' => 'integer', 'dbtype' => 'int', 'null' => false];
    }
    $object->_fieldMeta['option_id']['index'] = 'pk';
    $object->_fieldMeta['category_id']['index'] = 'pk';
    return $object;
}
function verify(bool $condition, string $name): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    echo 'PASS: ' . $name . PHP_EOL;
}
$object = linkProbe();
$object->fromArray(['option_id' => 7, 'category_id' => 434, 'active' => 1, 'required' => 0]);
verify($object->_fields['option_id'] === 0 && $object->_fields['category_id'] === 0 && $object->_fields['active'] === 1,
    'Real xPDO fromArray skips composite primary keys by default');
$object = linkProbe();
$object->set('option_id', 7);
$object->set('category_id', 434);
$object->set('active', 1);
$object->set('required', 0);
verify($object->_fields['option_id'] === 7 && $object->_fields['category_id'] === 434 && $object->_fields['active'] === 1,
    'Explicit set preserves both composite primary keys');
echo 'OK: real xPDO primary-key contract' . PHP_EOL;
