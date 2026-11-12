<?php

namespace App\Tests\Mcp;

use App\Entity\PageReport;
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
 * Le tool MCP construisait ses données de page à part, sans le rapport pa11y :
 * l indicateur RGAA répondait « aucune erreur détectée » là où l écran, qui
 * passait le rapport, en listait des dizaines.
 */
class PageAiAnalyzeTest extends TestCase
{
    public function testItHandsThePa11yReportToTheModel(): void
    {
        $report = new PageReport();
        $report->setUrl('https://xolo.test/usage');
        $report->setRgaaScore(85.0);
        $report->setRgaaErrors([['code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']]);
        $report->setPa11yReport([['type' => 'error', 'code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']]);

        $reports = $this->createStub(PageReportRepository::class);
        $reports->method('find')->willReturn($report);

        $captured = [];
        $ai = $this->createMock(AiService::class);
        $ai->method('analyzePageByIndicator')->willReturnCallback(
            static function (array $pageData, string $indicator) use (&$captured): ?array {
                $captured[] = ['indicator' => $indicator, 'data' => $pageData];

                return null;
            }
        );

        $resource = new PageMcpResource(
            $reports,
            $this->createStub(AnalysisRepository::class),
            $this->createStub(LighthouseService::class),
            $this->createStub(Pa11yService::class),
            $ai,
            $this->createStub(InspectService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $resource->aiAnalyze(['pageId' => 7], null);

        $this->assertCount(5, $captured);
        $this->assertContains('rgaa', array_column($captured, 'indicator'));

        foreach ($captured as $call) {
            $this->assertSame(
                [['type' => 'error', 'code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']],
                $call['data']['pa11yReport']
            );
        }

        $this->assertSame('Page AI analysis completed', $result['message']);
        $this->assertSame('https://xolo.test/usage', $result['url']);
        $this->assertSame(0, $result['resultsCount']);
    }
}
