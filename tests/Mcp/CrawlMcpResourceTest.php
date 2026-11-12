<?php

namespace App\Tests\Mcp;

use App\Entity\Site;
use App\Mcp\Resources\CrawlMcpResource;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Un crawl qui en cache un autre laisse le site bloqué sur « running » ; et
 * ce que le crawl écarte est l'information qui manquait pour comprendre une
 * analyse vide. Les deux doivent être lisibles par l'agent.
 */
class CrawlMcpResourceTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testItRefusesToStartACrawlAlreadyRunning(): void
    {
        $site = new Site();
        $site->setName('Xolo DEV');
        $site->setRootUrl('https://xolo.test');
        $site->setCrawlStatus(Site::CRAWL_STATUS_RUNNING);

        $resource = new CrawlMcpResource($this->emReturning($site));

        $this->expectException(\RuntimeException::class);

        $resource->trigger(['siteId' => 1], null);
    }

    public function testItParsesTheDiscardedUrlsFromTheLog(): void
    {
        $site = $this->siteWithId();
        $log = $this->logPath($site);
        file_put_contents($log, implode("\n", [
            'TITLE:Crawl du site',
            'SKIP:https://xolo.test/mbr-42:duplicate_shape',
            'FOUND:200:https://xolo.test/:Accueil',
            'SKIP:https://xolo.test/orgs/17:duplicate_shape',
            'SKIP:https://autre.test/:redirect_external',
            'ERR:something',
        ]) . "\n");
        $this->tempFiles[] = $log;

        $resource = new CrawlMcpResource($this->emReturning($site));
        $result = $resource->status(['siteId' => $site->getId()], null);

        $this->assertFalse($result['logMissing']);
        $this->assertSame([
            ['url' => 'https://xolo.test/mbr-42', 'reason' => 'duplicate_shape'],
            ['url' => 'https://xolo.test/orgs/17', 'reason' => 'duplicate_shape'],
            ['url' => 'https://autre.test/', 'reason' => 'redirect_external'],
        ], $result['discarded']);
    }

    public function testItKeepsOnlyTheFiftyLastDiscardedUrls(): void
    {
        $site = $this->siteWithId();
        $log = $this->logPath($site);
        $lines = [];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = "SKIP:https://xolo.test/page-{$i}:duplicate_shape";
        }
        file_put_contents($log, implode("\n", $lines) . "\n");
        $this->tempFiles[] = $log;

        $resource = new CrawlMcpResource($this->emReturning($site));
        $result = $resource->status(['siteId' => $site->getId()], null);

        $this->assertCount(50, $result['discarded']);
        $this->assertSame('https://xolo.test/page-10', $result['discarded'][0]['url']);
        $this->assertSame('https://xolo.test/page-59', $result['discarded'][49]['url']);
    }

    public function testItReportsAMissingLogAndTheCrawlState(): void
    {
        $site = $this->siteWithId();
        $site->setCrawlStatus(Site::CRAWL_STATUS_COMPLETED);
        $site->setCrawledPages(12);
        $site->setCrawlMetadata(array_fill(0, 12, ['url' => 'https://xolo.test']));

        $resource = new CrawlMcpResource($this->emReturning($site));
        $result = $resource->status(['siteId' => $site->getId()], null);

        $this->assertTrue($result['logMissing']);
        $this->assertSame([], $result['discarded']);
        $this->assertSame(Site::CRAWL_STATUS_COMPLETED, $result['crawlStatus']);
        $this->assertSame(12, $result['crawledPages']);
        $this->assertSame(12, $result['metadataPages']);
    }

    private function siteWithId(): Site
    {
        $site = new Site();
        $site->setName('Xolo DEV');
        $site->setRootUrl('https://xolo.test');

        $property = new \ReflectionProperty(Site::class, 'id');
        $property->setValue($site, random_int(1_000_000, 9_999_999));

        return $site;
    }

    private function logPath(Site $site): string
    {
        return sys_get_temp_dir() . '/optisight_crawl_' . $site->getId() . '.log';
    }

    private function emReturning(Site $site): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($site);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return $em;
    }
}
