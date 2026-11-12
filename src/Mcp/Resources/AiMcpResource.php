<?php

namespace App\Mcp\Resources;

use App\Entity\Analysis;
use App\Entity\McpApiKey;
use App\Repository\AiSummaryRepository;
use App\Repository\AnalysisRepository;

class AiMcpResource
{
    public function __construct(
        private AnalysisRepository $analysisRepository,
        private AiSummaryRepository $aiSummaryRepository,
        private string $projectDir
    ) {
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

        $summary = $this->aiSummaryRepository->findOneBy(['analysis' => $analysis]);

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

    /**
     * Lance la synthèse IA DÉTACHÉE, comme `crawl.trigger` et `audit.trigger`.
     *
     * Elle enchaîne cinq appels au modèle, espacés d'une pause : la faire dans
     * la requête MCP bloquait le client plusieurs minutes, et il coupait avant
     * la fin. `app:ai` écrit dans le même journal et le même fichier pid que le
     * flux de l'interface : la page de progression s'y rattache et le bouton
     * d'arrêt reste opérant.
     */
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

        $id = (int) $analysis->getId();
        $console = $this->projectDir . '/bin/console';
        $log = sys_get_temp_dir() . "/optisight_analysis_{$id}.log";
        $pidFile = sys_get_temp_dir() . "/optisight_analysis_{$id}.pid";
        @unlink($log);

        exec(sprintf(
            'nohup php %s app:ai %d >> %s 2>&1 < /dev/null & echo $! > %s',
            escapeshellarg($console),
            $id,
            escapeshellarg($log),
            escapeshellarg($pidFile)
        ));

        return [
            'message' => 'AI summary generation started in background; read it back with optisight.ai.summary',
            'auditId' => $id,
            'started' => true,
            'logFile' => $log,
        ];
    }
}
