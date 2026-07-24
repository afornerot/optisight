<?php

namespace App\Mcp\Resources;

use App\Entity\Analysis;
use App\Entity\McpApiKey;
use App\Repository\AnalysisRepository;
use App\Repository\PageReportRepository;
use Doctrine\ORM\EntityManagerInterface;

class AuditMcpResource
{
    private AnalysisRepository $analysisRepository;
    private PageReportRepository $pageReportRepository;
    private EntityManagerInterface $em;

    public function __construct(
        AnalysisRepository $analysisRepository,
        PageReportRepository $pageReportRepository,
        EntityManagerInterface $em
    ) {
        $this->analysisRepository = $analysisRepository;
        $this->pageReportRepository = $pageReportRepository;
        $this->em = $em;
    }

    public function list(array $arguments, ?McpApiKey $apiKey): array
    {
        $siteId = $arguments['siteId'] ?? null;

        if ($siteId) {
            $analyses = $this->analysisRepository->findBy(
                ['site' => $siteId],
                ['createdAt' => 'DESC']
            );
        } else {
            $analyses = $this->analysisRepository->findBy([], ['createdAt' => 'DESC'], 50);
        }

        $result = [];
        foreach ($analyses as $analysis) {
            $result[] = $this->formatAnalysisSummary($analysis);
        }

        return [
            'audits' => $result,
            'count' => count($result),
        ];
    }

    public function get(array $arguments, ?McpApiKey $apiKey): array
    {
        $auditId = $arguments['auditId'] ?? null;

        if (!$auditId) {
            throw new \InvalidArgumentException('auditId is required');
        }

        $analysis = $this->analysisRepository->find($auditId);

        if (!$analysis) {
            throw new \InvalidArgumentException("Audit not found: {$auditId}");
        }

        return $this->formatAnalysisDetail($analysis);
    }

    public function trigger(array $arguments, ?McpApiKey $apiKey): array
    {
        $siteId = $arguments['siteId'] ?? null;

        if (!$siteId) {
            throw new \InvalidArgumentException('siteId is required');
        }

        $site = $this->em->getRepository(\App\Entity\Site::class)->find($siteId);

        if (!$site) {
            throw new \InvalidArgumentException("Site not found: {$siteId}");
        }

        $analysis = new Analysis();
        $analysis->setSite($site);
        $analysis->setStatus(Analysis::STATUS_PENDING);

        $this->em->persist($analysis);
        $this->em->flush();

        return [
            'message' => 'Audit triggered successfully',
            'auditId' => $analysis->getId(),
            'status' => $analysis->getStatus(),
        ];
    }

    private function formatAnalysisSummary(Analysis $analysis): array
    {
        $site = $analysis->getSite();

        return [
            'id' => $analysis->getId(),
            'siteId' => $site?->getId(),
            'siteName' => $site?->getName(),
            'status' => $analysis->getStatus(),
            'createdAt' => $analysis->getCreatedAt()?->format('Y-m-d H:i:s'),
            'completedAt' => $analysis->getCompletedAt()?->format('Y-m-d H:i:s'),
            'pagesCrawled' => $analysis->getPagesCrawled(),
            'totalPages' => $analysis->getTotalPages(),
            'duration' => $analysis->getDuration(),
            'errorMessage' => $analysis->getErrorMessage(),
        ];
    }

    private function formatAnalysisDetail(Analysis $analysis): array
    {
        $site = $analysis->getSite();
        $reports = $this->pageReportRepository->findBy(['analysis' => $analysis]);

        $scores = $this->calculateAverageScores($reports);

        return [
            'id' => $analysis->getId(),
            'siteId' => $site?->getId(),
            'siteName' => $site?->getName(),
            'siteRootUrl' => $site?->getRootUrl(),
            'status' => $analysis->getStatus(),
            'createdAt' => $analysis->getCreatedAt()?->format('Y-m-d H:i:s'),
            'completedAt' => $analysis->getCompletedAt()?->format('Y-m-d H:i:s'),
            'pagesCrawled' => $analysis->getPagesCrawled(),
            'totalPages' => $analysis->getTotalPages(),
            'duration' => $analysis->getDuration(),
            'errorMessage' => $analysis->getErrorMessage(),
            'averageScores' => $scores,
            'pagesCount' => count($reports),
        ];
    }

    private function calculateAverageScores(array $reports): array
    {
        if (empty($reports)) {
            return [
                'lhPerformance' => null,
                'lhAccessibility' => null,
                'lhSeo' => null,
                'lhBestPractices' => null,
                'rgaaScore' => null,
            ];
        }

        $count = count($reports);
        $totals = ['lhPerformance' => 0, 'lhAccessibility' => 0, 'lhSeo' => 0, 'lhBestPractices' => 0, 'rgaaScore' => 0];
        $hasScore = $totals;

        foreach ($reports as $report) {
            if ($report->getLhPerformance() !== null) {
                $totals['lhPerformance'] += $report->getLhPerformance();
                $hasScore['lhPerformance']++;
            }
            if ($report->getLhAccessibility() !== null) {
                $totals['lhAccessibility'] += $report->getLhAccessibility();
                $hasScore['lhAccessibility']++;
            }
            if ($report->getLhSeo() !== null) {
                $totals['lhSeo'] += $report->getLhSeo();
                $hasScore['lhSeo']++;
            }
            if ($report->getLhBestPractices() !== null) {
                $totals['lhBestPractices'] += $report->getLhBestPractices();
                $hasScore['lhBestPractices']++;
            }
            if ($report->getRgaaScore() !== null) {
                $totals['rgaaScore'] += $report->getRgaaScore();
                $hasScore['rgaaScore']++;
            }
        }

        return [
            'lhPerformance' => $hasScore['lhPerformance'] > 0 ? round($totals['lhPerformance'] / $hasScore['lhPerformance'], 1) : null,
            'lhAccessibility' => $hasScore['lhAccessibility'] > 0 ? round($totals['lhAccessibility'] / $hasScore['lhAccessibility'], 1) : null,
            'lhSeo' => $hasScore['lhSeo'] > 0 ? round($totals['lhSeo'] / $hasScore['lhSeo'], 1) : null,
            'lhBestPractices' => $hasScore['lhBestPractices'] > 0 ? round($totals['lhBestPractices'] / $hasScore['lhBestPractices'], 1) : null,
            'rgaaScore' => $hasScore['rgaaScore'] > 0 ? round($totals['rgaaScore'] / $hasScore['rgaaScore'], 1) : null,
        ];
    }
}
