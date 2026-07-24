<?php

namespace App\Mcp\Resources;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\McpApiKey;
use App\Repository\AnalysisRepository;
use App\Repository\AiSummaryRepository;
use App\Repository\PageReportRepository;
use App\Service\AiService;
use Doctrine\ORM\EntityManagerInterface;

class AiMcpResource
{
    private AnalysisRepository $analysisRepository;
    private AiSummaryRepository $aiSummaryRepository;
    private PageReportRepository $pageReportRepository;
    private AiService $aiService;
    private EntityManagerInterface $em;

    public function __construct(
        AnalysisRepository $analysisRepository,
        AiSummaryRepository $aiSummaryRepository,
        PageReportRepository $pageReportRepository,
        AiService $aiService,
        EntityManagerInterface $em
    ) {
        $this->analysisRepository = $analysisRepository;
        $this->aiSummaryRepository = $aiSummaryRepository;
        $this->pageReportRepository = $pageReportRepository;
        $this->aiService = $aiService;
        $this->em = $em;
    }

    public function getSummary(array $arguments, ?McpApiKey $apiKey): array
    {
        $auditId = $arguments['auditId'] ?? null;

        if (!$auditId) {
            throw new \InvalidArgumentException('auditId is required');
        }

        $analysis = $this->analysisRepository->find($auditId);

        if (!$analysis) {
            throw new \InvalidArgumentException("Audit not found: {$auditId}");
        }

        $summary = $this->aiSummaryRepository->findOneBy(['analysis' => $analysis], ['createdAt' => 'DESC']);

        if (!$summary) {
            return [
                'auditId' => $auditId,
                'hasSummary' => false,
                'message' => 'No AI summary available for this audit',
            ];
        }

        return [
            'auditId' => $auditId,
            'hasSummary' => true,
            'summary' => $summary->getSummary(),
            'recommendations' => $summary->getRecommendations(),
            'summaryJson' => $summary->getSummaryJson(),
            'createdAt' => $summary->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    public function trigger(array $arguments, ?McpApiKey $apiKey): array
    {
        $auditId = $arguments['auditId'] ?? null;

        if (!$auditId) {
            throw new \InvalidArgumentException('auditId is required');
        }

        $analysis = $this->analysisRepository->find($auditId);

        if (!$analysis) {
            throw new \InvalidArgumentException("Audit not found: {$auditId}");
        }

        if ($analysis->getStatus() !== Analysis::STATUS_COMPLETED) {
            throw new \InvalidArgumentException("Audit must be completed to generate AI summary. Current status: {$analysis->getStatus()}");
        }

        $reports = $this->pageReportRepository->findBy(['analysis' => $analysis]);

        if (empty($reports)) {
            throw new \InvalidArgumentException("No page reports found for audit {$auditId}");
        }

        $pagesScores = [];
        $rgaaErrorAgg = [];
        $lhFailureAgg = [];
        $pa11yIssueAgg = [];

        foreach ($reports as $report) {
            $url = $report->getUrl();
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
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
            if (is_array($lhReport) && isset($lhReport['audits'])) {
                foreach ($lhReport['audits'] as $key => $audit) {
                    if (isset($audit['score']) && $audit['score'] < 1) {
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
        }

        uasort($rgaaErrorAgg, fn($a, $b) => $b['count'] <=> $a['count']);
        uasort($lhFailureAgg, fn($a, $b) => $b['count'] <=> $a['count']);
        uasort($pa11yIssueAgg, fn($a, $b) => $b['count'] <=> $a['count']);

        $rgaaErrorAgg = array_slice($rgaaErrorAgg, 0, 20, true);
        $lhFailureAgg = array_slice($lhFailureAgg, 0, 20, true);
        $pa11yIssueAgg = array_slice($pa11yIssueAgg, 0, 15, true);

        $pagesData = [
            'scores' => $pagesScores,
            'rgaa_errors_aggregated' => $rgaaErrorAgg,
            'lighthouse_failures_aggregated' => $lhFailureAgg,
            'pa11y_issues_aggregated' => $pa11yIssueAgg,
        ];

        $aiService = $this->aiService;

        $allResults = [];
        $indicators = \App\Service\AiService::INDICATORS;
        $labels = \App\Service\AiService::INDICATOR_LABELS;

        foreach ($indicators as $idx => $indicator) {
            $aiResult = $aiService->synthesizeReportByIndicator($pagesData, $indicator);
            if ($aiResult) {
                $allResults[$indicator] = $aiResult;
            }

            if ($idx < count($indicators) - 1) {
                usleep(1500000);
            }
        }

        if (!empty(array_filter($allResults, fn($r) => !empty($r['summary']) || !empty($r['recommendations'])))) {
            $existingSummary = $this->aiSummaryRepository->findOneBy(['analysis' => $analysis]);
            if ($existingSummary) {
                $this->em->remove($existingSummary);
            }

            $summary = new AiSummary();
            $summary->setAnalysis($analysis);

            $globalSummary = '';
            $allRecommendations = [];
            foreach ($allResults as $indicator => $r) {
                if (!empty($r['summary'])) {
                    $label = $labels[$indicator];
                    $globalSummary .= "**{$label}** : " . $r['summary'] . "\n\n";
                }
                if (!empty($r['recommendations'])) {
                    $allRecommendations = array_merge($allRecommendations, $r['recommendations']);
                }
            }

            $summary->setSummary(trim($globalSummary));
            $summary->setRecommendations($allRecommendations);
            $summary->setSummaryJson($allResults);

            $this->em->persist($summary);
            $this->em->flush();

            return [
                'message' => 'AI summary generated successfully',
                'auditId' => $auditId,
                'summaryId' => $summary->getId(),
                'indicatorsProcessed' => array_keys($allResults),
                'recommendationsCount' => count($allRecommendations),
            ];
        }

        return [
            'message' => 'AI summary generation failed - no results obtained',
            'auditId' => $auditId,
            'indicatorsProcessed' => array_keys($allResults),
        ];
    }
}
