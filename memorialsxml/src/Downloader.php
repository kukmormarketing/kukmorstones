<?php
declare(strict_types=1);
namespace MemorialsXml;

use RuntimeException;

final class Downloader
{
    public function __construct(private array $config) {}

    public function checkUrl(string $url): void
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['https', 'http'], true)
            || !in_array(strtolower($p['host'] ?? ''), $this->config['allowed_hosts'], true)
            || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && !in_array($p['port'], [80, 443], true))) {
            throw new RuntimeException('Недопустимый URL/домен: ' . $url);
        }
    }

    public function download(string $url, string $target, int $limit): void
    {
        $this->checkUrl($url);
        $out = fopen($target, 'wb');
        if ($out === false) { throw new RuntimeException('Нет доступа для записи: ' . $target); }
        $ch = curl_init($url);
        $bytes = 0;
        try {
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS_STR => 'http,https',
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => (int)$this->config['http_timeout'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $this->config['http_user_agent'] ?? ('curl/' . curl_version()['version']),
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, &$bytes, $limit): int {
                    $bytes += strlen($chunk);
                    if ($bytes > $limit) { return 0; }
                    return fwrite($out, $chunk) ?: 0;
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200 || $bytes === 0) {
                throw new RuntimeException('Ошибка загрузки HTTP ' . $status . ': ' . curl_error($ch) . ($bytes > $limit ? ' (превышен лимит)' : ''));
            }
        } finally {
            fclose($out);
        }
    }

    public function snapshot(string $source, string $dir): string
    {
        $target = $dir . '/feed.xml';
        if (preg_match('~^https?://~i', $source)) {
            $this->download($source, $target, (int)$this->config['max_feed_bytes']);
        } else {
            if (str_contains($source, '://') || !is_file($source) || !is_readable($source)) {
                throw new RuntimeException('Недоступный локальный XML: ' . $source);
            }
            $in = fopen($source, 'rb');
            $out = fopen($target, 'wb');
            if (!$in || !$out) { throw new RuntimeException('Не удалось скопировать XML'); }
            try {
                $size = stream_copy_to_stream($in, $out, (int)$this->config['max_feed_bytes'] + 1);
                if ($size === false || $size < 1 || $size > $this->config['max_feed_bytes']) {
                    throw new RuntimeException('Пустой XML или превышен лимит размера');
                }
            } finally { fclose($in); fclose($out); }
        }
        return $target;
    }
}
