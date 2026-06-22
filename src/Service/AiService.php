<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AiService
{
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

    /**
     * Synthesize an analysis report from page data using an LLM.
     *
     * @param array<int, array{url: string, lhPerformance: ?float, lhAccessibility: ?float, lhSeo: ?float, rgaaScore: ?float}> $pagesData
     * @return array{summary: string, recommendations: array}|null
     */
    public function synthesizeReport(array $pagesData): ?array
    {
        if (empty($this->apiKey) || $this->apiKey === 'changeme') {
            $this->logger->info('AI API key not configured, skipping synthesis');
            return null;
        }

        $pagesSummary = [];
        foreach ($pagesData['scores'] as $p) {
            $pagesSummary[] = [
                'path' => $p['path'] ?? '/',
                'lhPerf' => $p['lhPerf'] ?? null,
                'lhA11y' => $p['lhA11y'] ?? null,
                'lhSeo' => $p['lhSeo'] ?? null,
                'lhBp' => $p['lhBp'] ?? null,
                'rgaa' => $p['rgaa'] ?? null,
            ];
        }

        $prompt = <<<PROMPT
Tu es un expert en accessibilité web (RGAA 4.1), SEO, performance et bonnes pratiques.
Analyse les données agrégées du site ci-dessous et fournis des recommandations PRÉCISES et ACTIONNABLES.

Pour chaque recommandation, tu DOIS inclure :
- "category": accessibilité | performance | SEO | bonnes_pratiques
- "priority": HIGH | MEDIUM | LOW
- "title": titre court et explicite
- "pages": liste des CHEMINS (pas les URLs complètes) des pages affectées. Max 5 chemins par recommandation. Utilise "toutes les pages" si >5 pages concernées.
- "details": description PRÉCISE en une seule string avec \\n pour les retours à la ligne. Contenant :
  * Le critère RGAA ou l'audit Lighthouse concerné
  * Les étapes concrètes pour corriger avec exemples de code
  * L'impact utilisateur

Limite ta réponse à 10 recommandations maximum.

Scores par page :
PROMPT;

        $prompt .= json_encode($pagesSummary, JSON_UNESCAPED_UNICODE);

        if (!empty($pagesData['rgaa_errors_aggregated'])) {
            $prompt .= "\n\nErreurs RGAA agrégées :\n";
            $prompt .= json_encode($pagesData['rgaa_errors_aggregated'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($pagesData['lighthouse_failures_aggregated'])) {
            $prompt .= "\n\nÉchecs Lighthouse agrégés :\n";
            $prompt .= json_encode($pagesData['lighthouse_failures_aggregated'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($pagesData['pa11y_issues_aggregated'])) {
            $prompt .= "\n\nIssues pa11y agrégées :\n";
            $prompt .= json_encode($pagesData['pa11y_issues_aggregated'], JSON_UNESCAPED_UNICODE);
        }

        $prompt .= <<<PROMPT

RÈGLES STRICTES :
- "summary" DOIT être une string, PAS un objet. 2-3 phrases max.
- "details" DOIT être une string avec \\n, PAS un objet avec criterion/steps/impact.
- "pages" DOIT être un tableau de chemins courts (ex: "/nos-services"), max 5 par recommandation.
- Maximum 10 recommandations au total.
- PAS de markdown, PAS de backticks.

Réponds UNIQUEMENT en JSON valide :
{
  "summary": "Texte de résumé",
  "recommendations": [
    {
      "category": "accessibilité",
      "priority": "HIGH",
      "title": "Titre court",
      "pages": ["/chemin1"],
      "details": "Description avec\\nretours à la ligne"
    }
  ]
}
PROMPT;

        $this->logger->info('AI prompt built', [
            'prompt_length' => mb_strlen($prompt),
        ]);

        try {
            $response = $this->http->request('POST', $this->baseUrl . '/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => "Tu es un expert web accessibility et SEO. Réponds toujours en JSON valide. Pas de markdown, pas de backticks. Sois précis, cite les pages et critères concernés."],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 8192,
                ],
                'timeout' => 120,
            ]);

            $data = $response->toArray(false);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return null;
            }

            $content = trim($content);

            $this->logger->info('AI raw response', [
                'length' => mb_strlen($content),
                'starts_with' => mb_substr($content, 0, 50),
                'json_last_error' => null,
            ]);

            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            $result = json_decode($content, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                $this->logger->warning('JSON decode failed, trying to extract JSON from content', [
                    'error' => $jsonError,
                    'content_start' => mb_substr($content, 0, 200),
                ]);

                if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                    $result = json_decode($matches[0], true);
                    $jsonError = json_last_error();
                }
            }

            if (is_array($result) && isset($result['summary'])) {
                $this->logger->info('AI response parsed successfully', [
                    'has_summary' => isset($result['summary']),
                    'summary_type' => gettype($result['summary']),
                    'recommendations_count' => count($result['recommendations'] ?? []),
                ]);

                $result['summary'] = $this->normalizeSummary($result['summary']);

                if (isset($result['recommendations']) && is_array($result['recommendations'])) {
                    foreach ($result['recommendations'] as &$rec) {
                        if (!isset($rec['pages']) || !is_array($rec['pages'])) {
                            $rec['pages'] = [];
                        }
                        if (isset($rec['details']) && is_array($rec['details'])) {
                            $rec['details'] = $this->formatDetails($rec['details']);
                        }
                    }
                    unset($rec);
                }
                return $result;
            }

            $this->logger->error('AI response could not be parsed as JSON', [
                'json_error' => $jsonError,
                'content_preview' => mb_substr($content, 0, 500),
            ]);

            return ['summary' => $content, 'recommendations' => []];
        } catch (\Exception $e) {
            $this->logger->error('AI synthesis failed', ['error' => $e->getMessage()]);
            return null;
        }
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
