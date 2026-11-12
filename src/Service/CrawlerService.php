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
    private bool $dedupShape;
    /** @var string[] */
    private array $shapeIdPrefixes;
    /** @var string[] */
    private array $shapeIdParents;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
    ) {
        $this->http = $http;
        $this->logger = $logger;
        $this->maxPages = (int) $this->env('CRAWL_MAX_PAGES', '500');
        $this->maxDepth = (int) $this->env('CRAWL_MAX_DEPTH', '10');
        $this->delayMs = (int) $this->env('CRAWL_DELAY_MS', '1000');
        $this->dedupShape = $this->env('CRAWL_DEDUP_SHAPE', '1') !== '0';
        $this->shapeIdPrefixes = $this->segments('CRAWL_SHAPE_ID_PREFIXES');
        $this->shapeIdParents = $this->segments('CRAWL_SHAPE_MASK_AFTER');
    }

    /** @return string[] */
    private function segments(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->env($name, '')))));
    }

    /**
     * `$_ENV` ne porte pas les variables du processus : sans ce repli sur
     * getenv(), aucun réglage passé au conteneur n'aurait d'effet.
     */
    private function env(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
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
        $seenShapes = [];
        $queue = [['url' => rtrim($rootUrl, '/'), 'depth' => 0]];
        $baseHost = parse_url($rootUrl, PHP_URL_HOST);
        $siteAuthority = $this->authorityOf($rootUrl);
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
                $leftSite = false;
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
                        if ($this->authorityOf($redirectUrl) !== $siteAuthority) {
                            $leftSite = true;
                            break;
                        }
                        $currentUrl = $redirectUrl;
                        $redirects++;
                    } else {
                        break;
                    }
                } while ($redirects < $maxRedirects);

                // Une page qui renvoie vers un autre site n'est pas auditée :
                // les moteurs la suivraient sans cookie, jusqu'à la connexion.
                if ($leftSite) {
                    if ($onPageFound) {
                        $onPageFound([
                            'url' => $url,
                            'status' => $statusCode,
                            'skipped' => true,
                            'reason' => 'redirect_external',
                        ]);
                    }
                    continue;
                }

                // L'URL retenue est celle où la page est servie : une redirection
                // interne ne doit pas faire analyser l'URL qui redirige.
                if ($currentUrl !== $url) {
                    if (isset($visited[$currentUrl])) {
                        continue;
                    }
                    $visited[$currentUrl] = true;
                }

                // Une page par forme : les variantes de filtre, de pagination ou
                // d'identifiant sont annoncées et rattachées à leur représentante.
                if ($this->dedupShape) {
                    $shape = $this->shapeOf($currentUrl);
                    if (isset($seenShapes[$shape])) {
                        if ($onPageFound) {
                            $onPageFound([
                                'url' => $currentUrl,
                                'status' => $statusCode,
                                'skipped' => true,
                                'reason' => 'duplicate_shape',
                                'representedBy' => $seenShapes[$shape],
                            ]);
                        }
                        continue;
                    }
                    $seenShapes[$shape] = $currentUrl;
                }

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
                    'url' => $currentUrl,
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

    /**
     * Autorité d'une URL : hôte et port effectif — deux autorités différentes ne
     * désignent pas le même site, même à hôte partagé.
     */
    private function authorityOf(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if ($port === null) {
            $port = parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443;
        }

        return $host . ':' . $port;
    }

    /**
     * Forme d'une URL : autorité et chemin, requête ignorée, chaque identifiant
     * replié sur « {id} ». Sont des identifiants les valeurs opaques et celles
     * que le site déclare — CRAWL_SHAPE_ID_PREFIXES pour les préfixes lisibles
     * (mbr-, mdl-…), CRAWL_SHAPE_MASK_AFTER pour les parents dont l'enfant en
     * est un (orgs).
     */
    private function shapeOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $previous = '';
        $segments = array_map(function (string $segment) use (&$previous): string {
            $masked = in_array($previous, $this->shapeIdParents, true)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)
                || preg_match('/^[0-9a-f]{16,}$/i', $segment)
                || preg_match('/^\d+$/', $segment);
            foreach ($this->shapeIdPrefixes as $prefix) {
                $masked = $masked || str_starts_with($segment, $prefix . '-');
            }
            $previous = $segment;

            return $masked ? '{id}' : $segment;
        }, array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $this->authorityOf($url) . '/' . implode('/', $segments);
    }

    private function extractInternalLinks(string $html, string $baseHost, ?callable $isExcluded = null, string $baseUrl = ''): array
    {
        $links = [];

        if (preg_match_all('#<a\s+[^>]*href=["\']([^"\']*)["\']#is', $html, $m)) {
            foreach ($m[1] as $rawHref) {
                // Les liens sortent échappés du HTML : sans décodage, une chaîne
                // de requête part avec « &amp; » et désigne une autre URL.
                $href = trim(html_entity_decode($rawHref, ENT_QUOTES | ENT_HTML5));

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
