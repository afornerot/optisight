<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class CrawlerService
{
    private HttpClientInterface $http;
    private LoggerInterface $logger;
    private int $maxPages;
    private int $maxDepth;
    private int $delayMs;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
    ) {
        $this->http = $http;
        $this->logger = $logger;
        $this->maxPages = (int) ($_ENV['CRAWL_MAX_PAGES'] ?? 500);
        $this->maxDepth = (int) ($_ENV['CRAWL_MAX_DEPTH'] ?? 10);
        $this->delayMs = (int) ($_ENV['CRAWL_DELAY_MS'] ?? 1000);
    }

    /**
     * @param callable|null $isExcluded   function(string $url): bool — return true if URL should be excluded
     */
    public function crawl(
        string $rootUrl,
        ?callable $onProgress = null,
        ?callable $shouldStop = null,
        ?callable $onPageFound = null,
        ?string $cookieHeader = null,
        ?callable $isExcluded = null
    ): array
    {
        $visited = [];
        $pages = [];
        $queue = [['url' => rtrim($rootUrl, '/'), 'depth' => 0]];
        $baseHost = parse_url($rootUrl, PHP_URL_HOST);
        $totalQueued = 1;

        while (!empty($queue) && count($pages) < $this->maxPages) {
            if ($shouldStop && $shouldStop()) {
                $this->logger->info('Crawl cancelled by user');
                break;
            }

            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($visited[$url]) || $depth > $this->maxDepth) {
                continue;
            }
            $visited[$url] = true;

            $statusCode = null;
            try {
                $headers = [
                    'User-Agent' => ($_ENV['CRAWL_USER_AGENT'] ?? 'iargaaseo-bot/1.0'),
                ];
                if ($cookieHeader !== null) {
                    $headers['Cookie'] = $cookieHeader;
                }

                $response = $this->http->request('GET', $url, [
                    'timeout' => 10,
                    'max_redirects' => 5,
                    'headers' => $headers,
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getContent(false);
                $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

                if (!str_contains($contentType, 'text/html') || $statusCode < 200 || $statusCode >= 400) {
                    if ($onPageFound) {
                        $onPageFound([
                            'url' => $url,
                            'status' => $statusCode,
                            'skipped' => true,
                            'reason' => !str_contains($contentType, 'text/html') ? 'not_html' : 'http_error',
                        ]);
                    }
                    continue;
                }

                $page = [
                    'url' => $url,
                    'status' => $statusCode,
                    'depth' => $depth,
                    'title' => $this->extractTitle($body),
                    'metaDescription' => $this->extractMetaDescription($body),
                    'h1Count' => $this->countH1($body),
                    'canonical' => $this->extractCanonical($body),
                    'links' => $this->extractInternalLinks($body, $baseHost, $isExcluded),
                ];
                $pages[] = $page;

                foreach ($page['links'] as $link) {
                    if (!isset($visited[$link])) {
                        $queue[] = ['url' => $link, 'depth' => $depth + 1];
                        $totalQueued++;
                    }
                }

                if ($onPageFound) {
                    $onPageFound($page);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Crawl failed for URL: ' . $url, ['error' => $e->getMessage()]);
                if ($onPageFound) {
                    $onPageFound([
                        'url' => $url,
                        'status' => null,
                        'skipped' => true,
                        'reason' => 'error',
                    ]);
                }
            }

            if ($onProgress) {
                $onProgress(count($pages), $totalQueued);
            }

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
        }

        return $pages;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(strip_tags(html_entity_decode($m[1])));
        }
        return null;
    }

    private function extractMetaDescription(string $html): ?string
    {
        if (preg_match('#<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']#is', $html, $m)) {
            return trim(html_entity_decode($m[1]));
        }
        if (preg_match('#<meta\s+content=["\'](.*?)["\']\s+name=["\']description["\']#is', $html, $m)) {
            return trim(html_entity_decode($m[1]));
        }
        return null;
    }

    private function extractCanonical(string $html): ?string
    {
        if (preg_match('#<link\s+rel=["\']canonical["\']\s+href=["\'](.*?)["\']#is', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function countH1(string $html): int
    {
        return substr_count(strtolower($html), '<h1');
    }

    private function extractInternalLinks(string $html, string $baseHost, ?callable $isExcluded = null): array
    {
        $links = [];

        if (preg_match_all('#<a\s+[^>]*href=["\']([^"\']*)["\']#is', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = trim($href);

                if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')) {
                    continue;
                }

                if (str_starts_with($href, '//')) {
                    $href = 'https:' . $href;
                } elseif (str_starts_with($href, '/')) {
                    $href = 'https://' . $baseHost . $href;
                }

                $fragment = parse_url($href, PHP_URL_FRAGMENT);
                if ($fragment !== null && $fragment !== '') {
                    $href = strtok($href, '#');
                }

                $linkHost = parse_url($href, PHP_URL_HOST);
                if ($linkHost === $baseHost) {
                    $normalized = rtrim($href, '/');
                    if (!$isExcluded || !$isExcluded($normalized)) {
                        $links[] = $normalized;
                    }
                }
            }
        }

        return array_unique($links);
    }
}
