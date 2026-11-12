<?php

namespace App\Tests\Mcp;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\Site;
use App\Mcp\Resources\PageMcpResource;
use App\Repository\AnalysisRepository;
use App\Repository\PageReportRepository;
use App\Service\AiService;
use App\Service\InspectService;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * page.analyze passait le JSON des cookies au lieu d'un en-tête Cookie : les
 * deux moteurs chargeaient la page de connexion, et le tool rendait les scores
 * de cette page — les mêmes pour tous les écrans.
 */
class PageAnalyzeCookiesTest extends TestCase
{
    public function testItHandsTheCookieHeaderToBothEngines(): void
    {
        $site = new Site();
        $site->setName('Xolo DEV');
        $site->setRootUrl('https://xolo.test');
        $site->setAuthCookies(json_encode([
            ['name' => 'xolo_auth_oidc', 'value' => 'abc123'],
            ['name' => 'PHPSESSID', 'value' => 'def456'],
        ], JSON_THROW_ON_ERROR));

        $analysis = new Analysis();
        $analysis->setSite($site);

        $report = new PageReport();
        $report->setAnalysis($analysis);
        $report->setUrl('https://xolo.test/orgs/acme/admin/roles');

        $reports = $this->createStub(PageReportRepository::class);
        $reports->method('find')->willReturn($report);

        $expectedHeader = 'xolo_auth_oidc=abc123; PHPSESSID=def456';

        $lighthouse = $this->createMock(LighthouseService::class);
        $lighthouse->expects($this->once())->method('start')
            ->with('https://xolo.test/orgs/acme/admin/roles', $expectedHeader)
            ->willReturn(null);
        $lighthouse->method('parse')->willReturn(null);

        $pa11y = $this->createMock(Pa11yService::class);
        $pa11y->expects($this->once())->method('start')
            ->with('https://xolo.test/orgs/acme/admin/roles', $expectedHeader)
            ->willReturn(null);
        $pa11y->method('parse')->willReturn(null);

        $resource = new PageMcpResource(
            $reports,
            $this->createStub(AnalysisRepository::class),
            $lighthouse,
            $pa11y,
            $this->createStub(AiService::class),
            $this->createStub(InspectService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $resource->analyze(['pageId' => 12], null);

        $this->assertSame('https://xolo.test/orgs/acme/admin/roles', $result['url']);
    }

    public function testItPassesNoCookieWhenTheSiteHasNone(): void
    {
        $site = new Site();
        $site->setName('Xolo DEV');
        $site->setRootUrl('https://xolo.test');

        $analysis = new Analysis();
        $analysis->setSite($site);

        $report = new PageReport();
        $report->setAnalysis($analysis);
        $report->setUrl('https://xolo.test/usage');

        $reports = $this->createStub(PageReportRepository::class);
        $reports->method('find')->willReturn($report);

        $lighthouse = $this->createMock(LighthouseService::class);
        $lighthouse->expects($this->once())->method('start')->with('https://xolo.test/usage', null)->willReturn(null);
        $lighthouse->method('parse')->willReturn(null);

        $pa11y = $this->createMock(Pa11yService::class);
        $pa11y->expects($this->once())->method('start')->with('https://xolo.test/usage', null)->willReturn(null);
        $pa11y->method('parse')->willReturn(null);

        $resource = new PageMcpResource(
            $reports,
            $this->createStub(AnalysisRepository::class),
            $lighthouse,
            $pa11y,
            $this->createStub(AiService::class),
            $this->createStub(InspectService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $resource->analyze(['pageId' => 7], null);
    }
}
