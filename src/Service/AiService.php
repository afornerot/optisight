<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AiService
{
    public const INDICATORS = ['performance', 'accessibilite', 'seo', 'bonnes_pratiques', 'rgaa'];

    public const INDICATOR_LABELS = [
        'performance' => 'Performance',
        'accessibilite' => 'Accessibilité',
        'seo' => 'SEO',
        'bonnes_pratiques' => 'Bonnes Pratiques',
        'rgaa' => 'RGAA',
    ];

    private HttpClientInterface $http;
    private LoggerInterface $logger;
    private string $model;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(HttpClientInterface $http, LoggerInterface $logger, string $model, string $apiKey, string $baseUrl)
    {
        $this->http = $http;
        $this->logger = $logger;
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->apiKey !== 'changeme';
    }

    // ── Per-page analysis by indicator ──

    public function analyzePageByIndicator(array $pageData, string $indicator): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = $pageData['url'] ?? '';
        $prompt = $this->buildPagePrompt($pageData, $indicator);

        $this->logger->info('AI page analysis prompt built', [
            'url' => $url,
            'indicator' => $indicator,
            'prompt_length' => mb_strlen($prompt),
        ]);

        $systemMessage = match ($indicator) {
            'performance' => "Tu es un expert en performance web. Analyse UNIQUEMENT les problèmes de performance. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'accessibilite' => "Tu es un expert en accessibilité web (RGAA 4.1 / WCAG 2.1). Analyse UNIQUEMENT les problèmes d'accessibilité Lighthouse. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'seo' => "Tu es un expert en SEO (référencement naturel). Analyse UNIQUEMENT les problèmes SEO. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'bonnes_pratiques' => "Tu es un expert en bonnes pratiques web. Analyse UNIQUEMENT les problèmes de bonnes pratiques Lighthouse. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'rgaa' => "Tu es un expert en accessibilité web (RGAA 4.1). Analyse UNIQUEMENT les erreurs et avertissements RGAA/pa11y. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
        };

        return $this->callLlm($prompt, $systemMessage, 4096);
    }

    private function buildPagePrompt(array $pageData, string $indicator): string
    {
        $url = $pageData['url'] ?? '';
        $lhPerf = $pageData['lhPerformance'] ?? null;
        $lhA11y = $pageData['lhAccessibility'] ?? null;
        $lhSeo = $pageData['lhSeo'] ?? null;
        $lhBp = $pageData['lhBestPractices'] ?? null;
        $rgaaScore = $pageData['rgaaScore'] ?? null;
        $seoTitle = $pageData['seoTitle'] ?? null;
        $seoDescription = $pageData['seoDescription'] ?? null;
        $seoH1Count = $pageData['seoH1Count'] ?? null;
        $seoCanonical = $pageData['seoCanonical'] ?? null;
        $lighthouseReport = $pageData['lighthouseReport'] ?? null;
        $pa11yReport = $pageData['pa11yReport'] ?? null;

        $indicatorLabel = self::INDICATOR_LABELS[$indicator] ?? $indicator;

        $prompt = "Tu analyses l'indicateur **{$indicatorLabel}** de la page {$url}.\n";
        $prompt .= "Concentre-toi EXCLUSIVEMENT sur cet indicateur. Ignore les autres.\n\n";

        switch ($indicator) {
            case 'performance':
                $prompt .= "Score Performance Lighthouse : {$lhPerf}/100\n\n";
                $lhFailures = $this->extractLighthouseFailures($lighthouseReport, 'performance');
                if (!empty($lhFailures)) {
                    $prompt .= "Échecs Lighthouse Performance :\n";
                    foreach (array_slice($lhFailures, 0, 20, true) as $key => $fail) {
                        $prompt .= "- {$fail['title']} ({$fail['displayValue']}) : {$fail['description']}\n";
                        if (!empty($fail['elements'])) {
                            $prompt .= "  Éléments concernés :\n";
                            foreach ($fail['elements'] as $el) {
                                $prompt .= "    * {$el}\n";
                            }
                        }
                    }
                } else {
                    $prompt .= "Aucun échec Lighthouse Performance détecté.\n";
                }
                break;

            case 'accessibilite':
                $prompt .= "Score Accessibilité Lighthouse : {$lhA11y}/100\n\n";
                $lhFailures = $this->extractLighthouseFailures($lighthouseReport, 'accessibility');
                if (!empty($lhFailures)) {
                    $prompt .= "Échecs Lighthouse Accessibilité :\n";
                    foreach (array_slice($lhFailures, 0, 20, true) as $key => $fail) {
                        $prompt .= "- {$fail['title']} ({$fail['displayValue']}) : {$fail['description']}\n";
                        if (!empty($fail['elements'])) {
                            $prompt .= "  Éléments concernés :\n";
                            foreach ($fail['elements'] as $el) {
                                $prompt .= "    * {$el}\n";
                            }
                        }
                    }
                } else {
                    $prompt .= "Aucun échec Lighthouse Accessibilité détecté.\n";
                }
                break;

            case 'seo':
                $prompt .= "Score SEO Lighthouse : {$lhSeo}/100\n";
                $prompt .= "Titre : {$seoTitle}\n";
                $prompt .= "Méta description : {$seoDescription}\n";
                $prompt .= "Nombre de H1 : {$seoH1Count}\n";
                $prompt .= "Canonical : {$seoCanonical}\n\n";
                $lhFailures = $this->extractLighthouseFailures($lighthouseReport, 'seo');
                if (!empty($lhFailures)) {
                    $prompt .= "Échecs Lighthouse SEO :\n";
                    foreach (array_slice($lhFailures, 0, 20, true) as $key => $fail) {
                        $prompt .= "- {$fail['title']} ({$fail['displayValue']}) : {$fail['description']}\n";
                        if (!empty($fail['elements'])) {
                            $prompt .= "  Éléments concernés :\n";
                            foreach ($fail['elements'] as $el) {
                                $prompt .= "    * {$el}\n";
                            }
                        }
                    }
                } else {
                    $prompt .= "Aucun échec Lighthouse SEO détecté.\n";
                }
                break;

            case 'bonnes_pratiques':
                $prompt .= "Score Bonnes Pratiques Lighthouse : {$lhBp}/100\n\n";
                $lhFailures = $this->extractLighthouseFailures($lighthouseReport, 'best-practices');
                if (!empty($lhFailures)) {
                    $prompt .= "Échecs Lighthouse Bonnes Pratiques :\n";
                    foreach (array_slice($lhFailures, 0, 20, true) as $key => $fail) {
                        $prompt .= "- {$fail['title']} ({$fail['displayValue']}) : {$fail['description']}\n";
                        if (!empty($fail['elements'])) {
                            $prompt .= "  Éléments concernés :\n";
                            foreach ($fail['elements'] as $el) {
                                $prompt .= "    * {$el}\n";
                            }
                        }
                    }
                } else {
                    $prompt .= "Aucun échec Lighthouse Bonnes Pratiques détecté.\n";
                }
                break;

            case 'rgaa':
                $prompt .= "Score RGAA/pa11y : {$rgaaScore}/100\n\n";
                $pa11yErrors = $this->extractPa11yIssues($pa11yReport, 'error');
                $pa11yWarnings = $this->extractPa11yIssues($pa11yReport, 'warning');
                if (!empty($pa11yErrors)) {
                    $prompt .= "Erreurs RGAA/pa11y (" . count($pa11yErrors) . " erreurs) :\n";
                    foreach (array_slice($pa11yErrors, 0, 30) as $err) {
                        $prompt .= "- [{$err['code']}] {$err['message']}";
                        if (!empty($err['selector'])) {
                            $prompt .= " | Sélecteur: {$err['selector']}";
                        }
                        if (!empty($err['context'])) {
                            $prompt .= " | HTML: " . mb_substr($err['context'], 0, 300);
                        }
                        $prompt .= "\n";
                    }
                }
                if (!empty($pa11yWarnings)) {
                    $prompt .= "\nAvertissements RGAA/pa11y (" . count($pa11yWarnings) . ") :\n";
                    foreach (array_slice($pa11yWarnings, 0, 15) as $warn) {
                        $prompt .= "- [{$warn['code']}] {$warn['message']}";
                        if (!empty($warn['selector'])) {
                            $prompt .= " | Sélecteur: {$warn['selector']}";
                        }
                        $prompt .= "\n";
                    }
                }
                if (empty($pa11yErrors) && empty($pa11yWarnings)) {
                    $prompt .= "Aucune erreur ni avertissement RGAA/pa11y détecté.\n";
                }
                break;
        }

        $prompt .= <<<PROMPT

RÈGLES STRICTES :
- Tu ne dois analyser QUE l'indicateur "{$indicatorLabel}".
- "summary" DOIT être une string. 2-4 phrases décrivant l'état de l'indicateur sur cette page.
- "recommendations" DOIT être un tableau de max 5 recommandations SPECIFIQUES à cet indicateur.
- IMPORTANT : Les données incluent les sélecteurs CSS et snippets HTML exacts des éléments en erreur. Tu DOIS reprendre ces sélecteurs exacts dans le champ "elements" de chaque recommandation. Ne donne PAS de sélecteurs génériques comme "svg[role='img']" ou "[aria-*]", mais les sélecteurs précis fournis dans les données.
- "elements" DOIT être un tableau de strings reprises EXACTEMENT des sélecteurs/snippets fournis dans les données d'entrée (pas de sélecteurs inventés ou généralisés).
- Chaque recommandation DOIT contenir :
  * "category": "{$indicator}"
  * "priority": HIGH | MEDIUM | LOW
  * "title": titre court et explicite
  * "elements": tableau de strings listant les éléments HTML concrets à modifier (sélecteur CSS + description)
  * "details": description PRÉCISE avec \\n pour les retours à la ligne (critère, étapes de correction, impact)
- PAS de markdown, PAS de backticks.

Réponds UNIQUEMENT en JSON valide :
{
  "summary": "Résumé de l'indicateur",
  "recommendations": [
    {
      "category": "{$indicator}",
      "priority": "HIGH",
      "title": "Titre court",
      "elements": ["selector — description"],
      "details": "Description avec\\nretours à la ligne"
    }
  ]
}
PROMPT;

        return $prompt;
    }

    // ── Global synthesis by indicator ──

    public function synthesizeReportByIndicator(array $pagesData, string $indicator): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $prompt = $this->buildGlobalPrompt($pagesData, $indicator);

        $indicatorLabel = self::INDICATOR_LABELS[$indicator] ?? $indicator;

        $this->logger->info('AI global synthesis prompt built', [
            'indicator' => $indicator,
            'prompt_length' => mb_strlen($prompt),
        ]);

        $systemMessage = match ($indicator) {
            'performance' => "Tu es un expert en performance web. Analyse UNIQUEMENT les problèmes de performance du site. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'accessibilite' => "Tu es un expert en accessibilité web (RGAA 4.1 / WCAG 2.1). Analyse UNIQUEMENT les problèmes d'accessibilité Lighthouse du site. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'seo' => "Tu es un expert en SEO. Analyse UNIQUEMENT les problèmes SEO du site. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'bonnes_pratiques' => "Tu es un expert en bonnes pratiques web. Analyse UNIQUEMENT les problèmes de bonnes pratiques du site. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
            'rgaa' => "Tu es un expert en accessibilité web (RGAA 4.1). Analyse UNIQUEMENT les erreurs RGAA/pa11y du site. Réponds toujours en JSON valide. Pas de markdown, pas de backticks.",
        };

        return $this->callLlm($prompt, $systemMessage, 8192);
    }

    private function buildGlobalPrompt(array $pagesData, string $indicator): string
    {
        $indicatorLabel = self::INDICATOR_LABELS[$indicator] ?? $indicator;

        $prompt = "Tu analyses l'indicateur **{$indicatorLabel}** de l'ensemble du site.\n";
        $prompt .= "Concentre-toi EXCLUSIVEMENT sur cet indicateur. Ignore les autres.\n\n";

        $pagesScores = $pagesData['scores'] ?? [];

        switch ($indicator) {
            case 'performance':
                $prompt .= "Scores Performance par page :\n";
                foreach ($pagesScores as $p) {
                    $path = $p['path'] ?? '/';
                    $score = $p['lhPerf'] ?? '-';
                    $prompt .= "- {$path} : {$score}/100\n";
                }
                $lhFailures = $pagesData['lighthouse_failures_aggregated'] ?? [];
                $perfFailures = $this->filterLighthouseAggByCategory($lhFailures, 'performance');
                if (!empty($perfFailures)) {
                    $prompt .= "\nÉchecs Lighthouse Performance agrégés :\n";
                    $prompt .= json_encode($perfFailures, JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'accessibilite':
                $prompt .= "Scores Accessibilité par page :\n";
                foreach ($pagesScores as $p) {
                    $path = $p['path'] ?? '/';
                    $score = $p['lhA11y'] ?? '-';
                    $prompt .= "- {$path} : {$score}/100\n";
                }
                $lhFailures = $pagesData['lighthouse_failures_aggregated'] ?? [];
                $a11yFailures = $this->filterLighthouseAggByCategory($lhFailures, 'accessibility');
                if (!empty($a11yFailures)) {
                    $prompt .= "\nÉchecs Lighthouse Accessibilité agrégés :\n";
                    $prompt .= json_encode($a11yFailures, JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'seo':
                $prompt .= "Scores SEO par page :\n";
                foreach ($pagesScores as $p) {
                    $path = $p['path'] ?? '/';
                    $score = $p['lhSeo'] ?? '-';
                    $prompt .= "- {$path} : {$score}/100\n";
                }
                $lhFailures = $pagesData['lighthouse_failures_aggregated'] ?? [];
                $seoFailures = $this->filterLighthouseAggByCategory($lhFailures, 'seo');
                if (!empty($seoFailures)) {
                    $prompt .= "\nÉchecs Lighthouse SEO agrégés :\n";
                    $prompt .= json_encode($seoFailures, JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'bonnes_pratiques':
                $prompt .= "Scores Bonnes Pratiques par page :\n";
                foreach ($pagesScores as $p) {
                    $path = $p['path'] ?? '/';
                    $score = $p['lhBp'] ?? '-';
                    $prompt .= "- {$path} : {$score}/100\n";
                }
                $lhFailures = $pagesData['lighthouse_failures_aggregated'] ?? [];
                $bpFailures = $this->filterLighthouseAggByCategory($lhFailures, 'best-practices');
                if (!empty($bpFailures)) {
                    $prompt .= "\nÉchecs Lighthouse Bonnes Pratiques agrégés :\n";
                    $prompt .= json_encode($bpFailures, JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'rgaa':
                $prompt .= "Scores RGAA/pa11y par page :\n";
                foreach ($pagesScores as $p) {
                    $path = $p['path'] ?? '/';
                    $score = $p['rgaa'] ?? '-';
                    $prompt .= "- {$path} : {$score}/100\n";
                }
                $rgaaErrors = $pagesData['rgaa_errors_aggregated'] ?? [];
                if (!empty($rgaaErrors)) {
                    $prompt .= "\nErreurs RGAA/pa11y agrégées :\n";
                    $prompt .= json_encode($rgaaErrors, JSON_UNESCAPED_UNICODE);
                }
                $pa11yIssues = $pagesData['pa11y_issues_aggregated'] ?? [];
                if (!empty($pa11yIssues)) {
                    $prompt .= "\nIssues pa11y agrégées :\n";
                    $prompt .= json_encode($pa11yIssues, JSON_UNESCAPED_UNICODE);
                }
                break;
        }

        $prompt .= <<<PROMPT

RÈGLES STRICTES :
- Tu ne dois analyser QUE l'indicateur "{$indicatorLabel}".
- "summary" DOIT être une string. 2-3 phrases max sur l'état de cet indicateur sur le site.
- "details" DOIT être une string avec \\n.
- "pages" DOIT être un tableau de chemins courts, max 5 par recommandation.
- Maximum 8 recommandations au total, SPECIFIQUES à "{$indicatorLabel}".
- PAS de markdown, PAS de backticks.

Réponds UNIQUEMENT en JSON valide :
{
  "summary": "Résumé de l'indicateur sur le site",
  "recommendations": [
    {
      "category": "{$indicator}",
      "priority": "HIGH",
      "title": "Titre court",
      "pages": ["/chemin1"],
      "details": "Description avec\\nretours à la ligne"
    }
  ]
}
PROMPT;

        return $prompt;
    }

    // ── Shared LLM call ──

    private function callLlm(string $prompt, string $systemMessage, int $maxTokens): ?array
    {
        try {
            $response = $this->http->request('POST', $this->baseUrl . '/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemMessage],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => $maxTokens,
                ],
                'timeout' => 120,
            ]);

            $data = $response->toArray(false);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return null;
            }

            $content = trim($content);

            $this->logger->info('AI response received', [
                'length' => mb_strlen($content),
            ]);

            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            $result = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                    $result = json_decode($matches[0], true);
                }
            }

            if (!is_array($result)) {
                return null;
            }

            if (isset($result['summary'])) {
                $result['summary'] = $this->normalizeSummary($result['summary']);
            }

            if (isset($result['recommendations']) && is_array($result['recommendations'])) {
                foreach ($result['recommendations'] as &$rec) {
                    if (!isset($rec['pages']) || !is_array($rec['pages'])) {
                        $rec['pages'] = [];
                    }
                    if (!isset($rec['elements']) || !is_array($rec['elements'])) {
                        $rec['elements'] = [];
                    }
                    if (isset($rec['details']) && is_array($rec['details'])) {
                        $rec['details'] = $this->formatDetails($rec['details']);
                    }
                }
                unset($rec);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('AI call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Helpers ──

    private function extractLighthouseFailures(?array $lighthouseReport, string $category): array
    {
        $failures = [];
        if (!is_array($lighthouseReport) || !isset($lighthouseReport['audits'])) {
            return $failures;
        }

        $categoryAudits = $lighthouseReport['categories'][$category]['auditRefs'] ?? [];
        $categoryAuditIds = array_column($categoryAudits, 'id');

        foreach ($lighthouseReport['audits'] as $key => $audit) {
            if (isset($audit['score']) && $audit['score'] < 1) {
                if (empty($categoryAuditIds) || in_array($key, $categoryAuditIds, true)) {
                    $elements = [];
                    if (!empty($audit['details']['items'])) {
                        foreach ($audit['details']['items'] as $item) {
                            if (!empty($item['selector'])) {
                                $elements[] = $item['selector'];
                            } elseif (!empty($item['node']['selector'])) {
                                $elements[] = $item['node']['selector'];
                            } elseif (!empty($item['node']['snippet'])) {
                                $elements[] = mb_substr($item['node']['snippet'], 0, 200);
                            }
                        }
                    }

                    $failures[$key] = [
                        'title' => $audit['title'] ?? $key,
                        'description' => $audit['description'] ?? '',
                        'displayValue' => $audit['displayValue'] ?? '',
                        'score' => $audit['score'],
                        'elements' => array_slice($elements, 0, 10),
                    ];
                }
            }
        }

        return $failures;
    }

    private function extractPa11yIssues(?array $pa11yReport, string $type): array
    {
        $issues = [];
        if (!is_array($pa11yReport)) {
            return $issues;
        }

        foreach ($pa11yReport as $issue) {
            if (($issue['type'] ?? '') === $type) {
                $issues[] = [
                    'code' => $issue['code'] ?? '',
                    'message' => $issue['message'] ?? '',
                    'context' => $issue['context'] ?? '',
                    'selector' => $issue['selector'] ?? '',
                ];
            }
        }

        return $issues;
    }

    private function filterLighthouseAggByCategory(array $lhFailureAgg, string $category): array
    {
        $filtered = [];
        foreach ($lhFailureAgg as $key => $data) {
            $title = strtolower($data['title'] ?? '');
            $match = match ($category) {
                'performance' => str_contains($title, 'speed') || str_contains($title, 'fast') || str_contains($title, 'slow')
                    || str_contains($key, 'speed') || str_contains($key, 'fast') || str_contains($key, 'performance')
                    || str_contains($key, 'fcp') || str_contains($key, 'lcp') || str_contains($key, 'cls')
                    || str_contains($key, 'tbt') || str_contains($key, 'tti') || str_contains($key, 'si')
                    || str_contains($key, 'render') || str_contains($key, 'load') || str_contains($key, 'network')
                    || str_contains($key, 'image') || str_contains($key, 'javascript') || str_contains($key, 'css')
                    || str_contains($key, 'font') || str_contains($key, 'cache') || str_contains($key, 'compress'),
                'accessibility' => str_contains($key, 'aria') || str_contains($key, 'alt') || str_contains($key, 'label')
                    || str_contains($key, 'heading') || str_contains($key, 'color') || str_contains($key, 'contrast')
                    || str_contains($key, 'link') || str_contains($key, 'form') || str_contains($key, 'table')
                    || str_contains($key, 'list') || str_contains($key, 'image') || str_contains($key, 'td-headers')
                    || str_contains($key, 'th') || str_contains($key, 'video') || str_contains($key, 'audio')
                    || str_contains($key, 'nav') || str_contains($key, 'region') || str_contains($key, 'skip')
                    || str_contains($key, 'tabindex') || str_contains($key, 'focus'),
                'seo' => str_contains($key, 'meta') || str_contains($key, 'title') || str_contains($key, 'canonical')
                    || str_contains($key, 'description') || str_contains($key, 'hreflang') || str_contains($key, 'http-status')
                    || str_contains($key, 'robots') || str_contains($key, 'structured') || str_contains($key, 'viewport')
                    || str_contains($key, 'document-title') || str_contains($key, 'is-crawlable')
                    || str_contains($key, 'font-size') || str_contains($key, 'plugins') || str_contains($key, 'tap-targets'),
                'bonnes_pratiques' => str_contains($key, 'https') || str_contains($key, 'paste')
                    || str_contains($key, 'geolocation') || str_contains($key, 'notification')
                    || str_contains($key, 'xss') || str_contains($key, 'inspector-issue')
                    || str_contains($key, 'deprecated') || str_contains($key, 'error')
                    || str_contains($key, 'third-party') || str_contains($key, 'badge')
                    || str_contains($key, 'button') || str_contains($key, 'input'),
                default => false,
            };

            if ($match) {
                $filtered[$key] = $data;
            }
        }

        return $filtered;
    }

    private function normalizeSummary(mixed $summary): string
    {
        if (is_string($summary)) {
            return $summary;
        }

        if (is_array($summary)) {
            $parts = [];
            foreach ($summary as $key => $value) {
                if (is_array($value)) {
                    $subParts = [];
                    foreach ($value as $subKey => $subValue) {
                        if (is_array($subValue)) {
                            $subValue = json_encode($subValue, JSON_UNESCAPED_UNICODE);
                        }
                        $subParts[] = "{$subKey}: {$subValue}";
                    }
                    $value = implode(', ', $subParts);
                }
                $parts[] = "{$key}: {$value}";
            }
            return implode('. ', $parts) . '.';
        }

        return (string) $summary;
    }

    private function formatDetails(array $details): string
    {
        $parts = [];
        if (isset($details['criterion'])) {
            $parts[] = $details['criterion'];
        }
        if (isset($details['steps']) && is_array($details['steps'])) {
            $parts[] = "Étapes :\n" . implode("\n", array_map(fn($s, $i) => ($i + 1) . ". {$s}", $details['steps'], array_keys($details['steps'])));
        }
        if (isset($details['impact'])) {
            $parts[] = "Impact : {$details['impact']}";
        }
        return implode("\n\n", $parts);
    }
}
