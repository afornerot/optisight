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
use App\Service\InspectService;
use Doctrine\ORM\EntityManagerInterface;

class PageMcpResource
{
    private PageReportRepository $pageReportRepository;
    private AnalysisRepository $analysisRepository;
    private LighthouseService $lighthouseService;
    private Pa11yService $pa11yService;
    private AiService $aiService;
    private InspectService $inspectService;
    private EntityManagerInterface $em;

    public function __construct(
        PageReportRepository $pageReportRepository,
        AnalysisRepository $analysisRepository,
        LighthouseService $lighthouseService,
        Pa11yService $pa11yService,
        AiService $aiService,
        InspectService $inspectService,
        EntityManagerInterface $em
    ) {
        $this->pageReportRepository = $pageReportRepository;
        $this->analysisRepository = $analysisRepository;
        $this->lighthouseService = $lighthouseService;
        $this->pa11yService = $pa11yService;
        $this->aiService = $aiService;
        $this->inspectService = $inspectService;
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

        // Les deux moteurs tournent en parallele : les demarrer avant d'en
        // attendre un divise le temps de l'analyse par page.
        $lhProcess = $this->lighthouseService->start($url, $cookieHeader);
        $paProcess = $this->pa11yService->start($url, $cookieHeader);
        $lhResult = $this->lighthouseService->parse($lhProcess, $url);
        if ($lhResult) {
            $report->setLhPerformance($lhResult['performance']);
            $report->setLhAccessibility($lhResult['accessibility']);
            $report->setLhSeo($lhResult['seo']);
            $report->setLhBestPractices($lhResult['bestPractices']);
            $report->setLighthouseReport($lhResult['raw']);
        }

        $paResult = $this->pa11yService->parse($paProcess, $url);
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

        $pageData = AiService::pageData($report);

        $results = [];
        $indicators = AiService::INDICATORS;

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

    /**
     * Measure the facts behind one finding on a page: computed colour, background,
     * opacity chain, contrast ratio, target size, accessible name.
     *
     * Pass either a CSS `selector`, or a Pa11y `rule` (with an optional `index`
     * for the nth occurrence in the page) whose selector is then taken from the
     * stored report — that keeps the measurement tied to the finding.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function inspect(array $arguments, ?McpApiKey $apiKey): array
    {
        $pageId = $arguments['pageId'] ?? null;

        if (!$pageId) {
            throw new \InvalidArgumentException('pageId is required');
        }

        $report = $this->pageReportRepository->find($pageId);

        if (!$report) {
            throw new \InvalidArgumentException("Page not found: {$pageId}");
        }

        $url = $report->getUrl();

        if (!$url) {
            throw new \InvalidArgumentException("Page has no URL: {$pageId}");
        }

        $selector = isset($arguments['selector']) && (string) $arguments['selector'] !== ''
            ? (string) $arguments['selector']
            : null;

        $rule = isset($arguments['rule']) && (string) $arguments['rule'] !== ''
            ? (string) $arguments['rule']
            : null;

        $index = (int) ($arguments['index'] ?? 0);
        $wait = (int) ($arguments['wait'] ?? 1500);

        $cookieHeader = $report->getAnalysis()?->getSite()?->getCookieHeader();

        $finding = null;

        if ($rule !== null) {
            $finding = $this->findPa11yFinding($report, $rule, $index);

            if ($finding === null) {
                throw new \InvalidArgumentException(
                    "No Pa11y finding matches rule \"{$rule}\" at index {$index} on page {$pageId}. " .
                    'Check the rule code with page.get, or pass an explicit selector.'
                );
            }

            if ($finding['selector'] === '') {
                throw new \InvalidArgumentException(
                    "The Pa11y finding for rule \"{$rule}\" carries no selector; pass an explicit selector."
                );
            }

            $selector = $finding['selector'];
        }

        if ($selector === null) {
            throw new \InvalidArgumentException('selector is required (or rule + index)');
        }

        $result = $this->inspectService->inspect($url, $selector, $cookieHeader, $wait);

        if ($result === null) {
            throw new \RuntimeException('Inspection failed: the browser could not measure the node.');
        }

        if (($result['found'] ?? false) !== true) {
            throw new \InvalidArgumentException(sprintf(
                'Node not found on %s for selector "%s". The selector may depend on a page state — ' .
                'an open tab, a drawer, a collapsed menu: open that state, or pass a selector that matches in it.',
                $url,
                $selector
            ));
        }

        $result['pageId'] = $report->getId();
        $result['url'] = $url;
        $result['rule'] = $rule;
        $result['finding'] = $finding;

        return $result;
    }

    /**
     * Find the nth Pa11y issue whose code contains the given rule fragment.
     *
     * @return array{rule: string, message: string, selector: string}|null
     */
    private function findPa11yFinding(PageReport $report, string $rule, int $index): ?array
    {
        $issues = $report->getPa11yReport();

        if (!is_array($issues)) {
            return null;
        }

        $matches = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $code = (string) ($issue['code'] ?? '');

            if ($code !== '' && str_contains($code, $rule)) {
                $matches[] = $issue;
            }
        }

        if ($index < 0 || !isset($matches[$index])) {
            return null;
        }

        $issue = $matches[$index];

        $pa11ySelector = (string) ($issue['selector'] ?? '');
        $snippet = (string) ($issue['context'] ?? '');

        return [
            'rule' => (string) ($issue['code'] ?? $rule),
            'message' => (string) ($issue['message'] ?? ''),
            'selector' => $this->stableSelector($pa11ySelector, $snippet),
            'pa11ySelector' => $pa11ySelector,
            'snippet' => $snippet,
        ];
    }

    /**
     * Le sélecteur de Pa11y est souvent un identifiant généré à chaque rendu
     * (`#id-XXXX`) : il ne retrouve rien à l'inspection. On en déduit un
     * sélecteur stable depuis l'extrait HTML du constat — `name` d'abord, puis
     * un `id` lisible, puis la première classe.
     */
    private function stableSelector(string $selector, string $snippet): string
    {
        $generated = '/#(?:id-[A-Za-z0-9]{8,}|[A-Za-z0-9]{20,})$/';

        if ($selector !== '' && preg_match($generated, $selector) !== 1) {
            return $selector;
        }

        if ($snippet !== '') {
            if (preg_match('/\bname="([^"]+)"/', $snippet, $matches) === 1) {
                return '[name="' . $matches[1] . '"]';
            }

            if (preg_match('/\bid="([^"]+)"/', $snippet, $matches) === 1
                && preg_match('/^id-[A-Za-z0-9]{8,}$/', $matches[1]) !== 1) {
                return '#' . $matches[1];
            }

            if (preg_match('/<([a-z0-9]+)[^>]*\bclass="([^"]+)"/i', $snippet, $matches) === 1) {
                $class = explode(' ', trim($matches[2]))[0];

                if ($class !== '') {
                    return strtolower($matches[1]) . '.' . $class;
                }
            }
        }

        return $selector;
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
