<?php

namespace App\Tests\Service;

use App\Service\CrawlerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Un crawl doit retenir l'URL qui sert la page (une redirection la ferait
 * analyser sans cookie), ignorer ce qui sort du site, et ne garder qu'une page
 * par forme — filtres, pagination et identifiants en produisent des dizaines.
 */
class CrawlerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['CRAWL_DELAY_MS'] = 0;
        $_ENV['CRAWL_MAX_PAGES'] = 50;
        $_ENV['CRAWL_DEDUP_SHAPE'] = '1';
        $_ENV['CRAWL_SHAPE_ID_PREFIXES'] = '';
    }

    public function testItKeepsTheUrlThatServesThePageWhenTheRootRedirects(): void
    {
        $crawler = $this->crawler([
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['location' => '/admin/']]),
            new MockResponse('<html lang="fr"><title>Vue d ensemble</title><h1>Vue</h1></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/admin');

        $this->assertCount(1, $pages);
        $this->assertSame('https://xolo.test/admin/', $pages[0]['url']);
        $this->assertSame(200, $pages[0]['status']);
        $this->assertSame('Vue d ensemble', $pages[0]['title']);
    }

    public function testItSendsTheCookieAlongTheInternalRedirect(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = ['url' => $url, 'headers' => $options['headers']];

            return count($seen) === 1
                ? new MockResponse('', ['http_code' => 307, 'response_headers' => ['location' => '/usage/']])
                : new MockResponse('<html lang="fr"><title>Usage</title></html>', ['response_headers' => ['content-type' => 'text/html']]);
        });

        (new CrawlerService($client, new NullLogger()))->crawl('https://xolo.test/usage', cookieHeader: 'xolo_session=abc');

        $this->assertCount(2, $seen);
        $this->assertSame('https://xolo.test/usage', $seen[0]['url']);
        $this->assertSame('https://xolo.test/usage/', $seen[1]['url']);
        $this->assertContains('Cookie: xolo_session=abc', $seen[1]['headers']);
    }

    public function testItDropsAPageThatLeavesTheSite(): void
    {
        $found = [];
        $crawler = $this->crawler([
            new MockResponse('<html lang="fr"><title>Accueil</title><a href="/login">Connexion</a></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://xolo.test:3011/realms/xolo/auth']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/', onPageFound: function (array $page) use (&$found): void {
            $found[] = $page;
        });

        $this->assertSame(['https://xolo.test'], array_column($pages, 'url'));
        $skipped = array_values(array_filter($found, static fn (array $p): bool => $p['skipped'] ?? false));
        $this->assertCount(1, $skipped);
        $this->assertSame('redirect_external', $skipped[0]['reason']);
        $this->assertSame('https://xolo.test/login', $skipped[0]['url']);
    }

    public function testItDecodesHtmlEntitiesInDiscoveredLinks(): void
    {
        $_ENV['CRAWL_DEDUP_SHAPE'] = '0';

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return count($requested) === 1
                ? new MockResponse(
                    '<html lang="fr"><title>Événements</title><a href="/events?scope=all&amp;view=personal">Tous</a></html>',
                    ['response_headers' => ['content-type' => 'text/html']]
                )
                : new MockResponse('<html lang="fr"><title>Événements</title></html>', ['response_headers' => ['content-type' => 'text/html']]);
        });

        $pages = (new CrawlerService($client, new NullLogger()))->crawl('https://xolo.test/events');

        $this->assertContains('https://xolo.test/events?scope=all&view=personal', $requested);
        $this->assertNotContains('https://xolo.test/events?scope=all&amp;view=personal', $requested);
        $this->assertSame(
            ['https://xolo.test/events', 'https://xolo.test/events?scope=all&view=personal'],
            array_column($pages, 'url')
        );
    }

    public function testItKeepsOnePagePerShapeWhenOnlyTheFiltersDiffer(): void
    {
        $found = [];
        $crawler = $this->crawler([
            new MockResponse(
                '<html lang="fr"><title>Usage</title><a href="/usage?range=7d">7 jours</a><a href="/usage?range=30d">30 jours</a><a href="/events">Événements</a></html>',
                ['response_headers' => ['content-type' => 'text/html']]
            ),
            new MockResponse('<html lang="fr"><title>Usage 7d</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Usage 30d</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Événements</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/usage', onPageFound: function (array $page) use (&$found): void {
            $found[] = $page;
        });

        // La page sans filtre représente la forme : les deux variantes de plage
        // sont écartées, annoncées, et rattachées à leur représentante.
        $this->assertSame(
            ['https://xolo.test/usage', 'https://xolo.test/events'],
            array_column($pages, 'url')
        );
        $duplicates = array_values(array_filter($found, static fn (array $p): bool => ($p['reason'] ?? '') === 'duplicate_shape'));
        $this->assertCount(2, $duplicates);
        $this->assertSame(
            ['https://xolo.test/usage?range=7d', 'https://xolo.test/usage?range=30d'],
            array_column($duplicates, 'url')
        );
        $this->assertSame('https://xolo.test/usage', $duplicates[0]['representedBy']);
    }

    public function testItFoldsTheIdentifierFamiliesTheSiteDeclares(): void
    {
        $_ENV['CRAWL_SHAPE_ID_PREFIXES'] = 'mbr,mdl';

        $crawler = $this->crawler([
            new MockResponse(
                '<html lang="fr"><title>Membres</title><a href="/members/mbr-alice/edit">Alice</a><a href="/members/mbr-bob/edit">Bob</a><a href="/members/new">Nouveau</a></html>',
                ['response_headers' => ['content-type' => 'text/html']]
            ),
            new MockResponse('<html lang="fr"><title>Alice</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Bob</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Nouveau</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/members');

        $this->assertSame(
            ['https://xolo.test/members', 'https://xolo.test/members/mbr-alice/edit', 'https://xolo.test/members/new'],
            array_column($pages, 'url')
        );
    }

    public function testItKeepsEveryPageWhenTheDedupIsTurnedOff(): void
    {
        $_ENV['CRAWL_DEDUP_SHAPE'] = '0';

        $crawler = $this->crawler([
            new MockResponse('<html lang="fr"><title>Usage</title><a href="/usage?range=7d">7 jours</a></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Usage 7d</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/usage');

        $this->assertSame(
            ['https://xolo.test/usage', 'https://xolo.test/usage?range=7d'],
            array_column($pages, 'url')
        );
    }

    public function testItReadsItsSettingsFromTheProcessEnvironment(): void
    {
        // Le conteneur passe ses réglages par l'environnement du processus :
        // sans ce repli, aucune variable passée à docker n'aurait d'effet.
        putenv('CRAWL_SHAPE_ID_PREFIXES=mdl');
        unset($_ENV['CRAWL_SHAPE_ID_PREFIXES']);

        try {
            $crawler = $this->crawler([
                new MockResponse(
                    '<html lang="fr"><title>Modèles</title><a href="/models/mdl-sonnet/edit">Sonnet</a><a href="/models/mdl-haiku/edit">Haiku</a></html>',
                    ['response_headers' => ['content-type' => 'text/html']]
                ),
                new MockResponse('<html lang="fr"><title>Sonnet</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
                new MockResponse('<html lang="fr"><title>Haiku</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            ]);

            $pages = $crawler->crawl('https://xolo.test/models');

            $this->assertSame(
                ['https://xolo.test/models', 'https://xolo.test/models/mdl-sonnet/edit'],
                array_column($pages, 'url')
            );
        } finally {
            putenv('CRAWL_SHAPE_ID_PREFIXES');
        }
    }

    public function testItFoldsTheIdentifiersTheSiteDeclaresAfterAParent(): void
    {
        $_ENV['CRAWL_SHAPE_MASK_AFTER'] = 'orgs';

        $crawler = $this->crawler([
            new MockResponse(
                '<html lang="fr"><title>Console</title><a href="/orgs/acme/admin">Acme</a><a href="/orgs/globex/admin">Globex</a></html>',
                ['response_headers' => ['content-type' => 'text/html']]
            ),
            new MockResponse('<html lang="fr"><title>Acme</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html lang="fr"><title>Globex</title></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $pages = $crawler->crawl('https://xolo.test/orgs');

        $this->assertSame(
            ['https://xolo.test/orgs', 'https://xolo.test/orgs/acme/admin'],
            array_column($pages, 'url')
        );
    }

    /**
     * @param MockResponse[] $responses
     */
    private function crawler(array $responses): CrawlerService
    {
        return new CrawlerService(new MockHttpClient($responses), new NullLogger());
    }
}
