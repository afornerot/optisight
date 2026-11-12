<?php

namespace App\Tests\Mcp;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Mcp\Resources\AuditMcpResource;
use App\Repository\AnalysisRepository;
use App\Repository\PageReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Les constats d un audit sont regroupes par forme de nœud (deux elements qui
 * ne different que par un identifiant genere sont un seul composant), et deux
 * analyses se comparent par URL et par regle pour dire ce qui a ete corrige,
 * ce qui est apparu, et si la revue passe.
 */
class AuditMcpResourceTest extends TestCase
{
    public function testItFoldsSelectorsThatOnlyDifferByAGeneratedIdentifier(): void
    {
        $report = $this->report('https://xolo.test/usage', [
            ['type' => 'error', 'code' => 'WCAG2AA.1_4_3', 'message' => 'Contraste insuffisant', 'selector' => '#id-4f2a9', 'context' => '<button>'],
            ['type' => 'error', 'code' => 'WCAG2AA.1_4_3', 'message' => 'Contraste insuffisant', 'selector' => '#id-b71c3', 'context' => '<button>'],
        ]);

        $resource = $this->resource($this->analysis(10, [$report]));

        $result = $resource->findings(['auditId' => 10, 'groupBy' => 'shape'], null);

        $this->assertSame(1, $result['count']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('#id-{id}', $result['groups'][0]['pattern']);
        $this->assertSame(2, $result['groups'][0]['count']);
        $this->assertSame(1, $result['groups'][0]['pages']);
        $this->assertSame('#id-4f2a9', $result['groups'][0]['representative']['selector']);
        $this->assertSame('WCAG2AA.1_4_3', $result['groups'][0]['rule']);
        $this->assertSame('pa11y', $result['groups'][0]['engine']);
    }

    public function testItFoldsNumericValuesInTheNodeShape(): void
    {
        $report = $this->report('https://xolo.test/usage', [
            ['type' => 'error', 'code' => 'WCAG2AA.1_4_3', 'message' => 'Contraste', 'selector' => '/html/body/div[2]/ul/li[3]', 'context' => '<a>'],
            ['type' => 'error', 'code' => 'WCAG2AA.1_4_3', 'message' => 'Contraste', 'selector' => '/html/body/div[7]/ul/li[9]', 'context' => '<a>'],
        ]);

        $result = $this->resource($this->analysis(10, [$report]))->findings(['auditId' => 10, 'groupBy' => 'shape'], null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('/html/body/div[{n}]/ul/li[{n}]', $result['groups'][0]['pattern']);
    }

    public function testCompareReportsFixedAppearedAndUnchangedPerRuleAndPage(): void
    {
        $baseReport = $this->report('https://xolo.test/usage', [
            ['type' => 'error', 'code' => 'R1', 'message' => 'Corrigee', 'selector' => '#a', 'context' => '<a>'],
            ['type' => 'error', 'code' => 'R3', 'message' => 'Inchangée', 'selector' => '#c', 'context' => '<a>'],
        ]);
        $baseReport->setLhAccessibility(80.0);
        $baseReport->setRgaaScore(90.0);

        $headReport = $this->report('https://xolo.test/usage', [
            ['type' => 'error', 'code' => 'R3', 'message' => 'Inchangée', 'selector' => '#c', 'context' => '<a>'],
            ['type' => 'error', 'code' => 'R2', 'message' => 'Apparue', 'selector' => '#b', 'context' => '<a>'],
        ]);
        $headReport->setLhAccessibility(85.0);
        $headReport->setRgaaScore(90.0);

        $resource = $this->resource($this->analysis(1, [$baseReport]), $this->analysis(2, [$headReport]));

        $result = $resource->compare(['baseAuditId' => 1, 'headAuditId' => 2], null);

        $this->assertSame(['R1'], array_column($result['fixed'], 'rule'));
        $this->assertSame(['R2'], array_column($result['appeared'], 'rule'));
        $this->assertSame(['R3'], array_column($result['unchanged'], 'rule'));
        $this->assertSame('https://xolo.test/usage', $result['fixed'][0]['url']);

        $this->assertSame(['base' => 1, 'head' => 1], [
            'base' => $result['unchanged'][0]['baseCount'],
            'head' => $result['unchanged'][0]['headCount'],
        ]);

        $this->assertSame(5.0, $result['scores']['delta']['lhAccessibility']);
        $this->assertSame(0.0, $result['scores']['delta']['rgaaScore']);

        // threshold 0 : un constat apparu suffit a faire echouer la revue.
        $this->assertFalse($result['pass']);
        $this->assertTrue($resource->compare(['baseAuditId' => 1, 'headAuditId' => 2, 'threshold' => 1], null)['pass']);
    }

    public function testFindingsRequiresAuditId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource($this->analysis(1, []))->findings([], null);
    }

    /**
     * @param array<int, array<string, mixed>> $pa11yIssues
     */
    private function report(string $url, array $pa11yIssues): PageReport
    {
        $report = new PageReport();
        $report->setUrl($url);
        $report->setPa11yReport($pa11yIssues);

        return $report;
    }

    /**
     * @param array<int, PageReport> $reports
     */
    private function analysis(int $id, array $reports): Analysis
    {
        $analysis = new Analysis();

        $reflection = new \ReflectionProperty(Analysis::class, 'id');
        $reflection->setValue($analysis, $id);

        $this->reportsByAnalysisId[$id] = $reports;

        return $analysis;
    }

    /** @var array<int, array<int, PageReport>> */
    private array $reportsByAnalysisId = [];

    private function resource(Analysis ...$analyses): AuditMcpResource
    {
        $byId = [];
        foreach ($analyses as $analysis) {
            $byId[(int) $analysis->getId()] = $analysis;
        }

        $analysisRepository = $this->createStub(AnalysisRepository::class);
        $analysisRepository->method('find')->willReturnCallback(
            static fn ($id): ?Analysis => $byId[(int) $id] ?? null
        );

        $pageReportRepository = $this->createStub(PageReportRepository::class);
        $pageReportRepository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $analysis = $criteria['analysis'] ?? null;

                return $analysis instanceof Analysis
                    ? ($this->reportsByAnalysisId[(int) $analysis->getId()] ?? [])
                    : [];
            }
        );

        return new AuditMcpResource(
            $analysisRepository,
            $pageReportRepository,
            $this->createStub(EntityManagerInterface::class)
        );
    }
}
