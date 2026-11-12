<?php

namespace App\Service;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Repository\AiSummaryRepository;
use App\Repository\PageReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Synthèse IA d'un audit : agrégation des rapports de page, appels au modèle
 * par indicateur, et persistance du résumé.
 *
 * Le tool MCP `ai.trigger` lançait sa propre copie de cette boucle, en
 * synchrone dans la requête HTTP : la duplication a été supprimée et il
 * détache désormais `app:ai`, qui passe par ici.
 */
class AiSynthesisService
{
    private const DEFAULT_INDICATOR_DELAY_MS = 1500;

    public function __construct(
        private AiService $ai,
        private PageReportRepository $pageReportRepository,
        private AiSummaryRepository $aiSummaryRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * @return list<PageReport>
     */
    public function reports(Analysis $analysis): array
    {
        return $this->pageReportRepository->findBy(['analysis' => $analysis]);
    }

    /**
     * Agrège les rapports en une charge utile pour le modèle : scores par page,
     * erreurs pa11y et échecs Lighthouse comptés par code, triés du plus
     * fréquent au plus rare, puis coupés aux 20/20/15 premiers.
     *
     * @param list<PageReport> $reports
     * @param callable(PageReport): void|null $onPage appelé pour chaque page, dans l'ordre
     *
     * @return array{scores: list<array<string, mixed>>, rgaa_errors_aggregated: array<string, mixed>, lighthouse_failures_aggregated: array<string, mixed>, pa11y_issues_aggregated: array<string, mixed>}
     */
    public function buildPagesData(array $reports, ?callable $onPage = null): array
    {
        $pagesScores = [];
        $rgaaErrorAgg = [];
        $lhFailureAgg = [];
        $pa11yIssueAgg = [];

        foreach ($reports as $report) {
            $url = $report->getUrl();
            $path = parse_url((string) $url, PHP_URL_PATH) ?: '/';

            $pagesScores[] = [
                'path' => $path,
                'lhPerf' => $report->getLhPerformance(),
                'lhA11y' => $report->getLhAccessibility(),
                'lhSeo' => $report->getLhSeo(),
                'lhBp' => $report->getLhBestPractices(),
                'rgaa' => $report->getRgaaScore(),
            ];

            $rgaaErrors = $report->getRgaaErrors();
            if (is_array($rgaaErrors)) {
                foreach ($rgaaErrors as $err) {
                    $criterion = $err['criterion'] ?? $err['test'] ?? 'unknown';
                    if (!isset($rgaaErrorAgg[$criterion])) {
                        $rgaaErrorAgg[$criterion] = ['count' => 0, 'pages' => [], 'sample' => ''];
                    }
                    $rgaaErrorAgg[$criterion]['count']++;
                    if (!in_array($path, $rgaaErrorAgg[$criterion]['pages'], true)) {
                        $rgaaErrorAgg[$criterion]['pages'][] = $path;
                    }
                    if (empty($rgaaErrorAgg[$criterion]['sample']) && !empty($err['message'])) {
                        $rgaaErrorAgg[$criterion]['sample'] = mb_substr($err['message'], 0, 100);
                    }
                }
            }

            $lhReport = $report->getLighthouseReport();
            if (is_array($lhReport) && isset($lhReport['audits']) && is_array($lhReport['audits'])) {
                foreach ($lhReport['audits'] as $key => $audit) {
                    if (!is_array($audit) || !isset($audit['score']) || $audit['score'] >= 1) {
                        continue;
                    }
                    if (!isset($lhFailureAgg[$key])) {
                        $lhFailureAgg[$key] = [
                            'title' => $audit['title'] ?? $key,
                            'count' => 0,
                            'displayValue' => $audit['displayValue'] ?? '',
                        ];
                    }
                    $lhFailureAgg[$key]['count']++;
                }
            }

            $pa11y = $report->getPa11yReport();
            if (is_array($pa11y)) {
                foreach ($pa11y as $issue) {
                    $code = $issue['code'] ?? 'unknown';
                    if (!isset($pa11yIssueAgg[$code])) {
                        $pa11yIssueAgg[$code] = [
                            'type' => $issue['type'] ?? 'unknown',
                            'count' => 0,
                            'sample' => mb_substr($issue['message'] ?? '', 0, 100),
                        ];
                    }
                    $pa11yIssueAgg[$code]['count']++;
                }
            }

            if ($onPage !== null) {
                $onPage($report);
            }
        }

        $byCount = static fn (array $a, array $b): int => $b['count'] <=> $a['count'];
        uasort($rgaaErrorAgg, $byCount);
        uasort($lhFailureAgg, $byCount);
        uasort($pa11yIssueAgg, $byCount);

        return [
            'scores' => $pagesScores,
            'rgaa_errors_aggregated' => array_slice($rgaaErrorAgg, 0, 20, true),
            'lighthouse_failures_aggregated' => array_slice($lhFailureAgg, 0, 20, true),
            'pa11y_issues_aggregated' => array_slice($pa11yIssueAgg, 0, 15, true),
        ];
    }

    /**
     * Interroge le modèle pour chaque indicateur et persiste le résumé.
     *
     * Le résumé précédent de cet audit est remplacé : la synthèse est une donnée
     * dérivée, entièrement régénérable, et l'écran ne lit que le plus récent —
     * l'accumuler ne ferait que croître sans être montré.
     *
     * @param array<string, mixed> $pagesData
     * @param callable(string, int, string): void|null $onStart  avant l'appel : indicateur, numéro, libellé
     * @param callable(string, ?array<string, mixed>): void|null $onResult après l'appel : indicateur, résultat (null si échec)
     */
    public function synthesize(Analysis $analysis, array $pagesData, ?callable $onStart = null, ?callable $onResult = null): ?AiSummary
    {
        $indicators = AiService::INDICATORS;
        $labels = AiService::INDICATOR_LABELS;
        $delayMs = $this->indicatorDelayMs();

        $allResults = [];

        foreach ($indicators as $idx => $indicator) {
            $num = $idx + 1;

            if ($onStart !== null) {
                $onStart($indicator, $num, $labels[$indicator]);
            }

            $result = $this->ai->synthesizeReportByIndicator($pagesData, $indicator);
            $allResults[$indicator] = $result ?? ['summary' => '', 'recommendations' => []];

            if ($onResult !== null) {
                $onResult($indicator, $result);
            }

            $this->logger->info('AI indicator completed', [
                'indicator' => $indicator,
                'num' => $num,
                'has_result' => $result !== null,
            ]);

            if ($idx < count($indicators) - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $hasSomething = array_filter(
            $allResults,
            static fn (array $r): bool => !empty($r['summary']) || !empty($r['recommendations'])
        );

        if ($hasSomething === []) {
            return null;
        }

        $globalSummary = '';
        $allRecommendations = [];
        foreach ($allResults as $indicator => $r) {
            if (!empty($r['summary'])) {
                $globalSummary .= '**' . $labels[$indicator] . '** : ' . $r['summary'] . "\n\n";
            }
            if (!empty($r['recommendations'])) {
                $allRecommendations = array_merge($allRecommendations, $r['recommendations']);
            }
        }

        $previous = $this->aiSummaryRepository->findBy(['analysis' => $analysis]);

        $summary = new AiSummary();
        $summary->setAnalysis($analysis);
        $summary->setSummary(trim($globalSummary));
        $summary->setRecommendations($allRecommendations);
        $summary->setSummaryJson($allResults);
        $this->em->persist($summary);

        // Remplacement dans la même transaction : une reprise échouée ne doit
        // pas laisser l'audit sans résumé du tout.
        foreach ($previous as $old) {
            $this->em->remove($old);
        }

        $this->em->flush();

        return $summary;
    }

    /**
     * Pause entre deux indicateurs, en millisecondes : cinq appels d'affilée
     * sur le même compte se font rate-limiter sans cela.
     */
    private function indicatorDelayMs(): int
    {
        $value = $_ENV['AI_INDICATOR_DELAY_MS'] ?? getenv('AI_INDICATOR_DELAY_MS');

        if ($value === false || $value === '') {
            return self::DEFAULT_INDICATOR_DELAY_MS;
        }

        return max(0, (int) $value);
    }
}
