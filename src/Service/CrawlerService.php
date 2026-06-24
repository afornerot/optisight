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
                    'User-Agent' => ($_ENV['CRAWL_USER_AGENT'] ?? 'optisight-bot/1.0'),
                ];
                if ($cookieHeader !== null) {
                    $headers['Cookie'] = $cookieHeader;
                }

                $redirects = 0;
                $currentUrl = $url;
                $maxRedirects = 5;
                do {
                    $response = $this->http->request('GET', $currentUrl, [
                        'timeout' => 10,
                        'max_redirects' => 0,
                        'headers' => $headers,
                    ]);

                    $statusCode = $response->getStatusCode();
                    $location = $response->getHeaders(false)['location'][0] ?? null;

                    if ($statusCode >= 300 && $statusCode < 400 && $location !== null) {
                        $redirectUrl = $location;
                        if (str_starts_with($location, '/')) {
                            $parts = parse_url($currentUrl);
                            $port = $parts['port'] ?? null;
                            $redirectUrl = $parts['scheme'] . '://' . $parts['host'] . ($port !== null ? ':' . $port : '') . $location;
                        }
                        $redirectHost = parse_url($redirectUrl, PHP_URL_HOST);
                        if ($redirectHost !== $baseHost) {
                            break;
                        }
                        $currentUrl = $redirectUrl;
                        $redirects++;
                    } else {
                        break;
                    }
                } while ($redirects < $maxRedirects);

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
                    'links' => $this->extractInternalLinks($body, $baseHost, $isExcluded, $rootUrl),
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

    private function extractInternalLinks(string $html, string $baseHost, ?callable $isExcluded = null, string $baseUrl = ''): array
    {
        $links = [];

        if (preg_match_all('#<a\s+[^>]*href=["\']([^"\']*)["\']#is', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = trim($href);

                if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')) {
                    continue;
                }

                if (str_starts_with($href, '//')) {
                    $href = parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $href;
                } elseif (str_starts_with($href, '/')) {
                    $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
                    $port = parse_url($baseUrl, PHP_URL_PORT);
                    $host = $baseHost . ($port ? ':' . $port : '');
                    $href = $scheme . '://' . $host . $href;
                }

                $fragment = parse_url($href, PHP_URL_FRAGMENT);
                if ($fragment !== null && $fragment !== '') {
                    $href = strtok($href, '#');
                }

                $linkHost = parse_url($href, PHP_URL_HOST);
                $linkPort = parse_url($href, PHP_URL_PORT);
                $basePort = parse_url($baseUrl, PHP_URL_PORT);
                $baseAuthority = $baseHost . ($basePort ? ':' . $basePort : '');
                $linkAuthority = $linkHost . ($linkPort ? ':' . $linkPort : '');
                if ($linkAuthority === $baseAuthority) {
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
