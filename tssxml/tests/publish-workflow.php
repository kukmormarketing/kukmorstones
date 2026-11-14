<?php
declare(strict_types=1);
namespace MODX\Revolution {
    class modParser { public function __construct($modx) {} }
    class User { public bool $sudo = true; public function get($key) { return $this->sudo; } }
    class Ms3 { public function initialize($context): void {} public function loadMap(): void {} }
    class Services {
        public array $entries;
        public function __construct() { $this->entries = ['ms3' => new Ms3()]; }
        public function get($key) { return $this->entries[$key]; }
        public function has($key): bool { return isset($this->entries[$key]); }
        public function add($key, $value): void { $this->entries[$key] = $value; }
    }
    class modX {
        public User $user;
        public Services $services;
        public $parser = null;
        public function __construct() { $this->user = new User(); $this->services = new Services(); }
    }
}
namespace {
    $dir = sys_get_temp_dir() . '/tss-workflow-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700); mkdir($dir . '/src');
    define('MODX_CORE_PATH', $dir . '/');
    copy(dirname(__DIR__) . '/console-publish-images.php', $dir . '/console-publish-images.php');
    foreach (['Feed.php', 'Downloader.php', 'Importer.php'] as $name) { file_put_contents($dir . '/src/' . $name, '<?php'); }
    file_put_contents($dir . '/config.example.php', "<?php return ['source_key'=>'tss','context'=>'web','root_id'=>1];");
    file_put_contents($dir . '/config.php', '<?php return [];');
    file_put_contents($dir . '/src/PublishBatch.php', <<<'STUB'
<?php
namespace TssXml;
class PublishBatch {
    public function __construct(...$args) {}
    public function run(...$args): array {
        if (!empty($_SESSION['fixture_exception'])) { throw new \RuntimeException('Publication failed'); }
        return ['phase'=>$_SESSION['fixture_publish_phase'] ?? 'publishing','cursor'=>20,'total'=>40,'published'=>20,'already_published'=>0,'errors'=>[]];
    }
}
STUB);
    file_put_contents($dir . '/console-batch.php', <<<'STUB'
<?php
if (!$imagesOnly || !$loadImages || $resetImport) { throw new \RuntimeException('Wrong gallery mode'); }
$_SESSION['fixture_image_calls'] = ($_SESSION['fixture_image_calls'] ?? 0) + 1;
$state = ['phase'=>$_SESSION['fixture_image_phase'] ?? 'images'];
STUB);
    function verify(bool $condition, string $name): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } echo 'PASS: ' . $name . PHP_EOL; }
    try {
        $_SESSION = [];
        $modx = new \MODX\Revolution\modX();
        $useCoreParser = true;
        ob_start(); require $dir . '/console-publish-images.php'; ob_end_clean();
        verify($_SESSION['Console']['completed'] === false && empty($_SESSION['fixture_image_calls']) && $modx->parser === null, 'Publication repeats before gallery and before parser override');
        $_SESSION['fixture_publish_phase'] = 'done';
        ob_start(); require $dir . '/console-publish-images.php'; ob_end_clean();
        verify($_SESSION['Console']['completed'] === false && $_SESSION['fixture_image_calls'] === 1 && $modx->parser instanceof \MODX\Revolution\modParser, 'Finished publication switches to automatic image-only queue');
        $_SESSION['fixture_image_phase'] = 'done';
        ob_start(); require $dir . '/console-publish-images.php'; ob_end_clean();
        verify($_SESSION['Console']['completed'] === true, 'Finished gallery stops automatic requests');
        $_SESSION['fixture_exception'] = true;
        $_SESSION['Console']['completed'] = false;
        $calls = $_SESSION['fixture_image_calls'];
        ob_start(); require $dir . '/console-publish-images.php'; ob_end_clean();
        verify($_SESSION['Console']['completed'] === true && $_SESSION['fixture_image_calls'] === $calls && !isset($state), 'Publication error stops before gallery and discards stale state');
        unset($_SESSION['fixture_exception']);
        $modx->user->sudo = false;
        ob_start(); require $dir . '/console-publish-images.php'; ob_end_clean();
        verify($_SESSION['Console']['completed'] === true && $_SESSION['fixture_image_calls'] === $calls, 'Unauthorised execution does not reach either phase');
        echo 'OK: publication-to-images Console workflow' . PHP_EOL;
    } finally {
        foreach (glob($dir . '/src/*') ?: [] as $p) { unlink($p); }
        rmdir($dir . '/src');
        foreach (glob($dir . '/*') ?: [] as $p) { unlink($p); }
        rmdir($dir);
    }
}
