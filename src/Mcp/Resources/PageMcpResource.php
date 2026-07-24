<?php

namespace App\Mcp\Resources;

use App\Entity\Analysis;
use App\Entity\McpApiKey;
use App\Entity\PageReport;
use App\Repository\PageReportRepository;
use App\Repository\AnalysisRepository;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use App\Service\AiService;
use Doctrine\ORM\EntityManagerInterface;

class PageMcpResource
{
    private PageReportRepository $pageReportRepository;
    private AnalysisRepository $analysisRepository;
    private LighthouseService $lighthouseService;
    private Pa11yService $pa11yService;
    private AiService $aiService;
    private EntityManagerInterface $em;

    public function __construct(
        PageReportRepository $pageReportRepository,
        AnalysisRepository $analysisRepository,
        LighthouseService $lighthouseService,
        Pa11yService $pa11yService,
        AiService $aiService,
        EntityManagerInterface $em
    ) {
        $this->pageReportRepository = $pageReportRepository;
        $this->analysisRepository = $analysisRepository;
        $this->lighthouseService = $lighthouseService;
        $this->pa11yService = $pa11yService;
        $this->aiService = $aiService;
        $this->em = $em;
    }

    public function list(array $arguments, ?McpApiKey $apiKey): array
    {
        $auditId = $arguments['auditId'] ?? null;

        if (!$auditId) {
            throw new \InvalidArgumentException('auditId is required');
        }

        $analysis = $this->analysisRepository->find($auditId);

        if (!$analysis) {
            throw new \InvalidArgumentException("Audit not found: {$auditId}");
        }

        $reports = $this->pageReportRepository->findBy(['analysis' => $analysis], ['url' => 'ASC']);

        $result = [];
        foreach ($reports as $report) {
            $result[] = $this->formatPageSummary($report);
        }

        return [
            'pages' => $result,
            'count' => count($result),
        ];
    }

    public function get(array $arguments, ?McpApiKey $apiKey): array
    {
        $pageId = $arguments['pageId'] ?? null;

        if (!$pageId) {
            throw new \InvalidArgumentException('pageId is required');
        }

        $report = $this->pageReportRepository->find($pageId);

        if (!$report) {
            throw new \InvalidArgumentException("Page not found: {$pageId}");
        }

        return $this->formatPageDetail($report);
    }

    public function analyze(array $arguments, ?McpApiKey $apiKey): array
    {
        $pageId = $arguments['pageId'] ?? null;

        if (!$pageId) {
            throw new \InvalidArgumentException('pageId is required');
        }

        $report = $this->pageReportRepository->find($pageId);

        if (!$report) {
            throw new \InvalidArgumentException("Page not found: {$pageId}");
        }

        $analysis = $report->getAnalysis();
        $site = $analysis?->getSite();
        $cookieHeader = $site?->getAuthCookies();
        $url = $report->getUrl();

        $lhResult = $this->lighthouseService->analyze($url, $cookieHeader);
        if ($lhResult) {
            $report->setLhPerformance($lhResult['performance']);
            $report->setLhAccessibility($lhResult['accessibility']);
            $report->setLhSeo($lhResult['seo']);
            $report->setLhBestPractices($lhResult['bestPractices']);
            $report->setLighthouseReport($lhResult['raw']);
        }

        $paResult = $this->pa11yService->analyze($url, $cookieHeader);
        if ($paResult) {
            $report->setRgaaScore($paResult['score']);
            $report->setRgaaErrors($paResult['errors']);
            $report->setRgaaWarnings($paResult['warnings']);
            $report->setPa11yReport($paResult['raw']);
        }

        $this->em->flush();

        return [
            'message' => 'Page analysis completed',
            'pageId' => $report->getId(),
            'url' => $report->getUrl(),
            'lighthouse' => $lhResult ? [
                'performance' => $lhResult['performance'],
                'accessibility' => $lhResult['accessibility'],
                'seo' => $lhResult['seo'],
                'bestPractices' => $lhResult['bestPractices'],
            ] : null,
            'rgaa' => $paResult ? [
                'score' => $paResult['score'],
                'errorsCount' => count($paResult['errors']),
                'warningsCount' => count($paResult['warnings']),
            ] : null,
        ];
    }

    public function aiAnalyze(array $arguments, ?McpApiKey $apiKey): array
    {
        $pageId = $arguments['pageId'] ?? null;

        if (!$pageId) {
            throw new \InvalidArgumentException('pageId is required');
        }

        $report = $this->pageReportRepository->find($pageId);

        if (!$report) {
            throw new \InvalidArgumentException("Page not found: {$pageId}");
        }

        $pageData = [
            'url' => $report->getUrl(),
            'lhPerformance' => $report->getLhPerformance(),
            'lhAccessibility' => $report->getLhAccessibility(),
            'lhSeo' => $report->getLhSeo(),
            'lhBestPractices' => $report->getLhBestPractices(),
            'rgaaScore' => $report->getRgaaScore(),
            'seoTitle' => $report->getSeoTitle(),
            'seoDescription' => $report->getSeoDescription(),
            'seoH1Count' => $report->getSeoH1Count(),
            'seoCanonical' => $report->getSeoCanonical(),
            'lighthouseReport' => $report->getLighthouseReport(),
        ];

        $results = [];
        $indicators = \App\Service\AiService::INDICATORS;

        foreach ($indicators as $indicator) {
            $result = $this->aiService->analyzePageByIndicator($pageData, $indicator);
            if ($result) {
                $results[$indicator] = $result;
            }
        }

        $report->setAiAnalysis($results);
        $report->setAiAnalysisAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'message' => 'Page AI analysis completed',
            'pageId' => $report->getId(),
            'url' => $report->getUrl(),
            'indicators' => array_keys($results),
            'resultsCount' => count($results),
        ];
    }

    private function formatPageSummary(PageReport $report): array
    {
        return [
            'id' => $report->getId(),
            'url' => $report->getUrl(),
            'httpStatus' => $report->getHttpStatus(),
            'lhPerformance' => $report->getLhPerformance(),
            'lhAccessibility' => $report->getLhAccessibility(),
            'lhSeo' => $report->getLhSeo(),
            'lhBestPractices' => $report->getLhBestPractices(),
            'rgaaScore' => $report->getRgaaScore(),
            'hasAiAnalysis' => $report->getAiAnalysis() !== null,
            'createdAt' => $report->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    private function formatPageDetail(PageReport $report): array
    {
        $analysis = $report->getAnalysis();

        return [
            'id' => $report->getId(),
            'url' => $report->getUrl(),
            'httpStatus' => $report->getHttpStatus(),
            'lighthouse' => [
                'performance' => $report->getLhPerformance(),
                'accessibility' => $report->getLhAccessibility(),
                'seo' => $report->getLhSeo(),
                'bestPractices' => $report->getLhBestPractices(),
            ],
            'rgaa' => [
                'score' => $report->getRgaaScore(),
                'errors' => $report->getRgaaErrors(),
                'warnings' => $report->getRgaaWarnings(),
            ],
            'seo' => [
                'title' => $report->getSeoTitle(),
                'description' => $report->getSeoDescription(),
                'h1Count' => $report->getSeoH1Count(),
                'canonical' => $report->getSeoCanonical(),
            ],
            'aiAnalysis' => $report->getAiAnalysis(),
            'aiAnalysisAt' => $report->getAiAnalysisAt()?->format('Y-m-d H:i:s'),
            'crawlMetadata' => $report->getCrawlMetadata(),
            'analysisId' => $analysis?->getId(),
            'createdAt' => $report->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
