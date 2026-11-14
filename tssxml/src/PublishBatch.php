<?php
declare(strict_types=1);
namespace TssXml;

use MiniShop3\Model\msProduct;
use MODX\Revolution\{modResource, modX};
use RuntimeException;
use Throwable;

/** Publish only resources marked as imported by this TSS source/context. */
final class PublishBatch
{
    public function __construct(private modX $modx, private array $config, private string $directory) {}

    private function belongs(modResource $resource): bool
    {
        $meta = ((array)$resource->get('properties'))['tssxml'] ?? [];
        return $resource->get('class_key') === msProduct::class
            && $resource->get('context_key') === $this->config['context']
            && !$resource->get('deleted')
            && ($meta['source'] ?? null) === $this->config['source_key'];
    }

    private function save(array $state): void
    {
        $file = $this->directory . '/state.json';
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($tmp, $file)) {
            throw new RuntimeException('Не удалось сохранить позицию публикации');
        }
        chmod($file, 0600);
    }

    public function run(int $limit = 20, int $seconds = 15, bool $reset = false): array
    {
        if ($limit < 1 || $limit > 100 || $seconds < 1 || $seconds > 60) { throw new RuntimeException('limit: 1..100; seconds: 1..60'); }
        $lockName = 'tssxml:' . substr(hash('sha256', $this->modx->getOption('dbname') . ':' . $this->modx->getOption('table_prefix')), 0, 48);
        $stmt = $this->modx->prepare('SELECT GET_LOCK(?, 0)');
        if (!$stmt || !$stmt->execute([$lockName]) || (int)$stmt->fetchColumn() !== 1) { throw new RuntimeException('Предыдущий импорт ТСС ещё работает'); }
        try { return $this->lockedRun($limit, $seconds, $reset); }
        finally {
            $stmt = $this->modx->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt) { $stmt->execute([$lockName]); }
        }
    }

    private function lockedRun(int $limit, int $seconds, bool $reset): array
    {
        $deadline = microtime(true) + $seconds;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) { throw new RuntimeException('Нет доступа к папке публикации'); }
        $file = $this->directory . '/state.json';
        if ($reset && is_file($file)) { unlink($file); }
        $signature = hash('sha256', json_encode([$this->config['context'], $this->config['source_key'], $this->config['root_id']], JSON_THROW_ON_ERROR));
        if (is_file($file)) {
            $state = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if ($state['signature'] !== $signature) { throw new RuntimeException('Изменена область публикации'); }
        } else {
            $ids = [];
            foreach ($this->modx->getIterator(modResource::class, [
                'class_key' => msProduct::class, 'context_key' => $this->config['context'],
                'deleted' => 0, 'published' => 0,
            ]) as $resource) {
                if ($this->belongs($resource) && !$resource->get('published')) { $ids[] = (int)$resource->get('id'); }
            }
            sort($ids, SORT_NUMERIC);
            $state = ['signature' => $signature, 'phase' => 'publishing', 'ids' => $ids, 'cursor' => 0,
                'total' => count($ids), 'published' => 0, 'already_published' => 0, 'skipped' => [], 'errors' => [], 'started_at' => date(DATE_ATOM)];
            $this->save($state);
        }
        if ($state['phase'] !== 'publishing') { return $state; }
        $handled = 0;
        try {
            while ($state['cursor'] < count($state['ids']) && $handled < $limit && microtime(true) < $deadline) {
                $id = $state['ids'][$state['cursor']];
                try {
                    $resource = $this->modx->getObject(modResource::class, $id);
                    if (!$resource || !$this->belongs($resource)) {
                        $state['skipped'][] = $id;
                    } elseif ($resource->get('published')) {
                        $state['already_published']++;
                    } else {
                        Importer::log('publish_start', ['id' => $id]);
                        $response = $this->modx->runProcessor('MODX\\Revolution\\Processors\\Resource\\Publish', ['id' => $id, 'syncsite' => 0, 'clearCache' => false]);
                        if (!$response || $response->isError()) { throw new RuntimeException(json_encode($response ? $response->getAllErrors() : ['Нет ответа'], JSON_UNESCAPED_UNICODE)); }
                        $saved = $this->modx->getObject(modResource::class, $id);
                        if (!$saved || !$saved->get('published')) { throw new RuntimeException('Не подтверждено published=1'); }
                        $state['published']++;
                        Importer::log('published', ['id' => $id]);
                    }
                } catch (Throwable $e) {
                    $state['errors'][] = ['id' => $id, 'error' => $e->getMessage()];
                    Importer::log('publish_error', ['id' => $id, 'error' => $e->getMessage()]);
                }
                $state['cursor']++;
                $handled++;
                $this->save($state);
            }
        } finally {
            if ($handled > 0) {
                $scope = ['contexts' => [$this->config['context']]];
                $this->modx->cacheManager->refresh(['resource' => $scope, 'auto_publish' => $scope, 'context_settings' => $scope]);
            }
        }
        if ($state['cursor'] >= count($state['ids'])) { $state['phase'] = $state['errors'] ? 'done_with_errors' : 'done'; }
        $this->save($state);
        return $state;
    }
}
