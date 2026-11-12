<?php

namespace App\Tests\Service;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Repository\AiSummaryRepository;
use App\Repository\PageReportRepository;
use App\Service\AiService;
use App\Service\AiSynthesisService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * L agrégation et la persistance vivaient en double, dans app:ai et dans le
 * tool MCP : les deux copies avaient déjà divergé (l une accumulait les
 * résumés, l autre supprimait le précédent).
 */
class AiSynthesisServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['AI_INDICATOR_DELAY_MS'] = '0';
    }

    public function testBuildPagesDataAggregatesByCodeAndVisitsEveryPageOnce(): void
    {
        $first = $this->report('https://xolo.test/a', [
            ['type' => 'error', 'code' => 'R1', 'message' => 'Contraste'],
            ['type' => 'error', 'code' => 'R1', 'message' => 'Contraste'],
            ['type' => 'warning', 'code' => 'R2', 'message' => 'Nom'],
        ]);
        $first->setRgaaErrors([
            ['criterion' => '10.7', 'message' => 'Contraste insuffisant'],
            ['criterion' => '10.7', 'message' => 'Contraste insuffisant'],
        ]);

        $second = $this->report('https://xolo.test/b', [
            ['type' => 'error', 'code' => 'R1', 'message' => 'Contraste'],
        ]);

        $visited = [];
        $service = $this->service(new AiService(new \Symfony\Component\HttpClient\MockHttpClient(), new NullLogger(), 'm', 'k', 'u'));

        $data = $service->buildPagesData([$first, $second], function (PageReport $report) use (&$visited): void {
            $visited[] = $report->getUrl();
        });

        $this->assertSame(['https://xolo.test/a', 'https://xolo.test/b'], $visited);
        $this->assertCount(2, $data['scores']);
        $this->assertSame('/a', $data['scores'][0]['path']);

        $this->assertSame(3, $data['pa11y_issues_aggregated']['R1']['count']);
        $this->assertSame(1, $data['pa11y_issues_aggregated']['R2']['count']);
        // Seule la première page porte des erreurs RGAA.
        $this->assertSame(['/a'], $data['rgaa_errors_aggregated']['10.7']['pages']);
        $this->assertSame(2, $data['rgaa_errors_aggregated']['10.7']['count']);
        $this->assertSame('Contraste insuffisant', $data['rgaa_errors_aggregated']['10.7']['sample']);
        $this->assertSame(['R1', 'R2'], array_keys($data['pa11y_issues_aggregated']));
    }

    public function testSynthesizeReplacesThePreviousSummary(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('synthesizeReportByIndicator')->willReturnCallback(
            static fn (array $pagesData, string $indicator): ?array => 'seo' === $indicator
                ? ['summary' => 'Résumé SEO', 'recommendations' => [['category' => 'seo', 'title' => 'Corriger le titre']]]
                : null
        );

        $old = new AiSummary();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('remove')->with($old);
        $em->expects($this->once())->method('flush');

        $service = new AiSynthesisService(
            $ai,
            $this->createStub(PageReportRepository::class),
            $this->summaries([$old]),
            $em,
            new NullLogger()
        );

        $summary = $service->synthesize(new Analysis(), ['scores' => []]);

        $this->assertInstanceOf(AiSummary::class, $summary);
        $this->assertSame('**SEO** : Résumé SEO', trim((string) $summary->getSummary()));
        $this->assertSame('Corriger le titre', $summary->getRecommendations()[0]['title']);
        $this->assertArrayHasKey('performance', $summary->getSummaryJson());
        $this->assertSame('', $summary->getSummaryJson()['performance']['summary']);
    }

    public function testSynthesizeReturnsNothingWhenTheModelAnswersNothing(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('synthesizeReportByIndicator')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $service = new AiSynthesisService(
            $ai,
            $this->createStub(PageReportRepository::class),
            $this->summaries(),
            $em,
            new NullLogger()
        );

        $this->assertNull($service->synthesize(new Analysis(), ['scores' => []]));
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    private function report(string $url, array $issues): PageReport
    {
        $report = new PageReport();
        $report->setUrl($url);
        $report->setPa11yReport($issues);

        return $report;
    }

    /**
     * @param array<int, AiSummary> $previous
     */
    private function summaries(array $previous = []): AiSummaryRepository
    {
        $summaries = $this->createStub(AiSummaryRepository::class);
        $summaries->method('findBy')->willReturn($previous);

        return $summaries;
    }

    /**
     * @param array<int, AiSummary> $previous
     */
    private function service(AiService $ai, array $previous = []): AiSynthesisService
    {
        return new AiSynthesisService(
            $ai,
            $this->createStub(PageReportRepository::class),
            $this->summaries($previous),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger()
        );
    }
}
