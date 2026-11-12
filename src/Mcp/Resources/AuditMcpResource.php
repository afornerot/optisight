<?php

namespace App\Mcp\Resources;

use App\Entity\Analysis;
use App\Entity\McpApiKey;
use App\Entity\PageReport;
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

        // Lancer l analyse en arriere-plan DETACHE : sans cela l audit reste
        // "pending" indefiniment, puisque le flux (et le moteur) ne demarre
        // qu a l ouverture de la page de progression.
        // Les fichiers pid/journal sont ceux de StreamCommandController : la
        // page de progression peut donc se rattacher au process deja lance,
        // et le bouton "Arret" (killProcessTree) reste operationnel.
        $id = (int) $analysis->getId();
        $console = dirname(__DIR__, 3) . '/bin/console';
        $log = sys_get_temp_dir() . "/optisight_analysis_{$id}.log";
        $pidFile = sys_get_temp_dir() . "/optisight_analysis_{$id}.pid";
        @unlink($log);

        exec(sprintf(
            'nohup php %s app:rgaa %d >> %s 2>&1 < /dev/null & echo $! > %s',
            escapeshellarg($console),
            $id,
            escapeshellarg($log),
            escapeshellarg($pidFile)
        ));

        return [
            'message' => 'Audit triggered successfully',
            'auditId' => $id,
            'status' => $analysis->getStatus(),
            'started' => true,
        ];
    }

    /**
     * Constats d une analyse, regroupes du plus gros levier au plus petit.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function findings(array $arguments, ?McpApiKey $apiKey): array
    {
        $auditId = $arguments['auditId'] ?? null;

        if (!$auditId) {
            throw new \InvalidArgumentException('auditId is required');
        }

        $groupBy = $arguments['groupBy'] ?? 'shape';

        if (!in_array($groupBy, ['shape', 'rule', 'page'], true)) {
            throw new \InvalidArgumentException("Unsupported groupBy: {$groupBy}");
        }

        $limit = (int) ($arguments['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }

        $analysis = $this->analysisRepository->find($auditId);

        if (!$analysis) {
            throw new \InvalidArgumentException("Audit not found: {$auditId}");
        }

        $reports = $this->pageReportRepository->findBy(['analysis' => $analysis]);
        $nodes = $this->extractFindings($reports);

        $groups = $groupBy === 'page'
            ? $this->groupFindingsByPage($nodes, $reports)
            : $this->groupFindings($nodes, $groupBy);

        $familyCount = count($groups);
        $total = count($nodes);
        $pageCount = count(array_unique(array_map(static fn (array $node): string => $node['url'], $nodes)));

        $groups = array_slice($groups, 0, $limit);

        $summary = $groupBy === 'page'
            ? sprintf('%d pages, %d nœuds en échec', $pageCount, $total)
            : sprintf('%d familles, %d nœuds, sur %d pages', $familyCount, $total, $pageCount);

        return [
            'auditId' => (int) $analysis->getId(),
            'groupBy' => $groupBy,
            'groups' => $groups,
            'count' => count($groups),
            'families' => $familyCount,
            'total' => $total,
            'pages' => $pageCount,
            'summary' => $summary,
        ];
    }

    /**
     * Compare deux analyses par URL de page et par regle, pour les deux moteurs.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function compare(array $arguments, ?McpApiKey $apiKey): array
    {
        $baseAuditId = $arguments['baseAuditId'] ?? null;

        if (!$baseAuditId) {
            throw new \InvalidArgumentException('baseAuditId is required');
        }

        $headAuditId = $arguments['headAuditId'] ?? null;

        if (!$headAuditId) {
            throw new \InvalidArgumentException('headAuditId is required');
        }

        $threshold = (int) ($arguments['threshold'] ?? 0);

        $base = $this->analysisRepository->find($baseAuditId);

        if (!$base) {
            throw new \InvalidArgumentException("Audit not found: {$baseAuditId}");
        }

        $head = $this->analysisRepository->find($headAuditId);

        if (!$head) {
            throw new \InvalidArgumentException("Audit not found: {$headAuditId}");
        }

        $baseReports = $this->pageReportRepository->findBy(['analysis' => $base]);
        $headReports = $this->pageReportRepository->findBy(['analysis' => $head]);

        $baseMap = $this->findingsByUrlAndRule($baseReports);
        $headMap = $this->findingsByUrlAndRule($headReports);

        $fixed = [];
        $appeared = [];
        $unchanged = [];

        foreach ($baseMap as $key => $entry) {
            if (!isset($headMap[$key])) {
                $fixed[] = $entry;
                continue;
            }

            $unchanged[] = [
                'url' => $entry['url'],
                'engine' => $entry['engine'],
                'rule' => $entry['rule'],
                'title' => $entry['title'],
                'baseCount' => $entry['count'],
                'headCount' => $headMap[$key]['count'],
            ];
        }

        foreach ($headMap as $key => $entry) {
            if (!isset($baseMap[$key])) {
                $appeared[] = $entry;
            }
        }

        $byCount = static fn (array $a, array $b): int => $b['count'] <=> $a['count'];
        usort($fixed, $byCount);
        usort($appeared, $byCount);

        $baseScores = $this->calculateAverageScores($baseReports);
        $headScores = $this->calculateAverageScores($headReports);

        $delta = [];
        foreach ($baseScores as $category => $value) {
            $delta[$category] = $value !== null && ($headScores[$category] ?? null) !== null
                ? round($headScores[$category] - $value, 1)
                : null;
        }

        $pass = count($appeared) <= $threshold;

        return [
            'baseAuditId' => (int) $base->getId(),
            'headAuditId' => (int) $head->getId(),
            'threshold' => $threshold,
            'fixed' => $fixed,
            'appeared' => $appeared,
            'unchanged' => $unchanged,
            'scores' => [
                'base' => $baseScores,
                'head' => $headScores,
                'delta' => $delta,
            ],
            'counts' => [
                'fixed' => count($fixed),
                'appeared' => count($appeared),
                'unchanged' => count($unchanged),
            ],
            'pass' => $pass,
            'summary' => $this->compareSummary((int) $base->getId(), (int) $head->getId(), $fixed, $appeared, $unchanged, $delta, $pass),
        ];
    }

    /**
     * Aplati les rapports en une liste de nœuds en echec (un par constat).
     *
     * @param array<int, PageReport> $reports
     * @return list<array{url: string, engine: string, rule: string, title: string, selector: string, snippet: string}>
     */
    private function extractFindings(array $reports): array
    {
        $nodes = [];

        foreach ($reports as $report) {
            $url = $report->getUrl() ?? '';

            $pa11y = $report->getPa11yReport();
            if (is_array($pa11y)) {
                foreach ($pa11y as $issue) {
                    if (!is_array($issue) || ($issue['type'] ?? '') !== 'error') {
                        continue;
                    }

                    $nodes[] = [
                        'url' => $url,
                        'engine' => 'pa11y',
                        'rule' => (string) ($issue['code'] ?? 'unknown'),
                        'title' => (string) ($issue['message'] ?? ($issue['code'] ?? 'unknown')),
                        'selector' => (string) ($issue['selector'] ?? ''),
                        'snippet' => (string) ($issue['context'] ?? ''),
                    ];
                }
            }

            $lighthouse = $report->getLighthouseReport();
            if (!is_array($lighthouse) || !isset($lighthouse['audits']) || !is_array($lighthouse['audits'])) {
                continue;
            }

            // Un constat, c'est un audit en échec d'une des trois catégories qui
            // désignent un défaut : accessibilité, SEO, bonnes pratiques. La
            // catégorie performance est faite de diagnostics (poids des
            // ressources, chaînes de dépendances, « insight ») dont les items
            // sont des requêtes et non des nœuds : les compter noyait le rapport
            // — 4 163 « constats » pour une analyse qui n'en a qu'une poignée.
            $referenced = [];
            foreach (['accessibility', 'seo', 'best-practices'] as $category) {
                foreach (($lighthouse['categories'][$category]['auditRefs'] ?? []) as $ref) {
                    if (is_array($ref) && isset($ref['id'])) {
                        $referenced[(string) $ref['id']] = true;
                    }
                }
            }

            foreach ($lighthouse['audits'] as $auditKey => $audit) {
                if (!is_array($audit) || !isset($audit['score']) || $audit['score'] >= 1) {
                    continue;
                }

                $rule = (string) ($audit['id'] ?? $auditKey);

                // Un rapport sans catégories (données anciennes) garde tout.
                if ($referenced !== [] && !isset($referenced[$rule])) {
                    continue;
                }
                $title = (string) ($audit['title'] ?? $rule);
                $items = $audit['details']['items'] ?? null;

                if (!is_array($items) || $items === []) {
                    // Constat sans nœud (ex. titre de document manquant) : il
                    // compte une fois pour la page, sinon il disparaitrait.
                    $nodes[] = [
                        'url' => $url,
                        'engine' => 'lighthouse',
                        'rule' => $rule,
                        'title' => $title,
                        'selector' => '',
                        'snippet' => '',
                    ];
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $node = $item['node'] ?? null;

                    $nodes[] = [
                        'url' => $url,
                        'engine' => 'lighthouse',
                        'rule' => $rule,
                        'title' => $title,
                        'selector' => is_array($node) ? (string) ($node['selector'] ?? '') : '',
                        'snippet' => is_array($node) ? (string) ($node['snippet'] ?? '') : '',
                    ];
                }
            }
        }

        return $nodes;
    }

    /**
     * Regroupe par regle (groupBy rule) ou par forme de nœud (groupBy shape).
     *
     * @param list<array{url: string, engine: string, rule: string, title: string, selector: string, snippet: string}> $nodes
     * @return list<array<string, mixed>>
     */
    private function groupFindings(array $nodes, string $groupBy): array
    {
        $groups = [];

        foreach ($nodes as $node) {
            $pattern = $this->normalizeSelector($node['selector']);
            $key = $groupBy === 'shape'
                ? $node['engine'] . '|' . $node['rule'] . '|' . $pattern
                : $node['engine'] . '|' . $node['rule'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'rule' => $node['rule'],
                    'engine' => $node['engine'],
                    'title' => $node['title'],
                    'pattern' => $pattern,
                    'count' => 0,
                    'pages' => 0,
                    'urls' => [],
                    'representative' => [
                        'page' => $node['url'],
                        'selector' => $node['selector'],
                        'snippet' => $node['snippet'],
                    ],
                ];
            }

            $groups[$key]['count']++;

            if (!in_array($node['url'], $groups[$key]['urls'], true)) {
                $groups[$key]['urls'][] = $node['url'];
            }
        }

        foreach ($groups as &$group) {
            $group['pages'] = count($group['urls']);
            $group['urls'] = array_slice($group['urls'], 0, 10);

            if ($groupBy !== 'shape') {
                unset($group['pattern']);
            }
        }
        unset($group);

        usort($groups, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: ($b['pages'] <=> $a['pages']));

        return $groups;
    }

    /**
     * Regroupe par page : scores de la page et regles en echec.
     *
     * @param list<array{url: string, engine: string, rule: string, title: string, selector: string, snippet: string}> $nodes
     * @param array<int, PageReport> $reports
     * @return list<array<string, mixed>>
     */
    private function groupFindingsByPage(array $nodes, array $reports): array
    {
        $reportByUrl = [];
        foreach ($reports as $report) {
            $reportByUrl[$report->getUrl() ?? ''] = $report;
        }

        $groups = [];

        foreach ($nodes as $node) {
            $url = $node['url'];

            if (!isset($groups[$url])) {
                $groups[$url] = [
                    'page' => $url,
                    'url' => $url,
                    'scores' => $this->pageScores($reportByUrl[$url] ?? null),
                    'count' => 0,
                    'pages' => 1,
                    'urls' => [$url],
                    'findings' => [],
                ];
            }

            $groups[$url]['count']++;

            $ruleKey = $node['engine'] . '|' . $node['rule'];
            if (!isset($groups[$url]['findings'][$ruleKey])) {
                $groups[$url]['findings'][$ruleKey] = [
                    'rule' => $node['rule'],
                    'engine' => $node['engine'],
                    'title' => $node['title'],
                    'count' => 0,
                ];
            }
            $groups[$url]['findings'][$ruleKey]['count']++;
        }

        foreach ($groups as &$group) {
            $findings = array_values($group['findings']);
            usort($findings, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $group['findings'] = $findings;
        }
        unset($group);

        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }

    /**
     * @param array<int, PageReport> $reports
     * @return array<string, array{url: string, engine: string, rule: string, title: string, count: int}>
     */
    private function findingsByUrlAndRule(array $reports): array
    {
        $map = [];

        foreach ($this->extractFindings($reports) as $node) {
            $key = $node['url'] . "\x00" . $node['engine'] . "\x00" . $node['rule'];

            if (!isset($map[$key])) {
                $map[$key] = [
                    'url' => $node['url'],
                    'engine' => $node['engine'],
                    'rule' => $node['rule'],
                    'title' => $node['title'],
                    'count' => 0,
                ];
            }

            $map[$key]['count']++;
        }

        return $map;
    }

    /**
     * @return array<string, float|null>
     */
    private function pageScores(?PageReport $report): array
    {
        if ($report === null) {
            return [
                'performance' => null,
                'accessibility' => null,
                'seo' => null,
                'bestPractices' => null,
                'rgaa' => null,
            ];
        }

        return [
            'performance' => $report->getLhPerformance(),
            'accessibility' => $report->getLhAccessibility(),
            'seo' => $report->getLhSeo(),
            'bestPractices' => $report->getLhBestPractices(),
            'rgaa' => $report->getRgaaScore(),
        ];
    }

    /**
     * Normalise un selecteur en une forme : identifiants generes et valeurs
     * numeriques remplaces, pour que deux nœuds du meme composant se
     * regroupent (#id-4f2a9 et #id-b71c3 donnent la meme famille).
     */
    private function normalizeSelector(string $selector): string
    {
        $normalized = preg_replace_callback(
            '/#([A-Za-z][A-Za-z0-9_]*)-([A-Za-z0-9]+)/',
            static function (array $match): string {
                $token = $match[2];
                $isGenerated = preg_match('/\d/', $token) === 1 || strlen($token) >= 4;

                return $isGenerated ? '#' . $match[1] . '-{id}' : $match[0];
            },
            $selector
        );

        if ($normalized === null) {
            $normalized = $selector;
        }

        $normalized = preg_replace('/\d+/', '{n}', $normalized);

        return $normalized ?? $selector;
    }

    /**
     * @param list<array<string, mixed>> $fixed
     * @param list<array<string, mixed>> $appeared
     * @param list<array<string, mixed>> $unchanged
     * @param array<string, float|null> $delta
     */
    private function compareSummary(
        int $baseId,
        int $headId,
        array $fixed,
        array $appeared,
        array $unchanged,
        array $delta,
        bool $pass
    ): string {
        $labels = [
            'rgaaScore' => 'RGAA',
            'lhAccessibility' => 'accessibilité',
            'lhSeo' => 'SEO',
            'lhBestPractices' => 'bonnes pratiques',
            'lhPerformance' => 'performance',
        ];

        $scoreParts = [];
        foreach ($labels as $key => $label) {
            if (($delta[$key] ?? null) === null) {
                continue;
            }

            $scoreParts[] = sprintf('%s %+.1f', $label, $delta[$key]);
        }

        $summary = sprintf(
            'Analyses #%d → #%d : %d corrigé(s), %d apparu(s), %d inchangé(s)',
            $baseId,
            $headId,
            count($fixed),
            count($appeared),
            count($unchanged)
        );

        if ($scoreParts !== []) {
            $summary .= ' ; ' . implode(', ', $scoreParts) . ' pts';
        }

        return $summary . ($pass ? ' — revue OK.' : ' — des constats sont apparus.');
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
