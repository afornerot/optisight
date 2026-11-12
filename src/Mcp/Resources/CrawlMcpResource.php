<?php

namespace App\Mcp\Resources;

use App\Entity\McpApiKey;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

class CrawlMcpResource
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function trigger(array $arguments, ?McpApiKey $apiKey): array
    {
        $siteId = $arguments['siteId'] ?? null;

        if (!$siteId) {
            throw new \InvalidArgumentException('siteId is required');
        }

        $site = $this->em->getRepository(Site::class)->find($siteId);

        if (!$site) {
            throw new \InvalidArgumentException("Site not found: {$siteId}");
        }

        if ($site->getCrawlStatus() === Site::CRAWL_STATUS_RUNNING) {
            throw new \RuntimeException(
                "Un crawl est déjà en cours pour le site {$siteId} : attends la fin (crawl.status) ou arrête-le avant d'en relancer un."
            );
        }

        $id = (int) $site->getId();
        $console = dirname(__DIR__, 3) . '/bin/console';
        $log = sys_get_temp_dir() . "/optisight_crawl_{$id}.log";
        $pidFile = sys_get_temp_dir() . "/optisight_crawl_{$id}.pid";
        @unlink($log);

        // Le crawl est détaché (nohup) : il doit survivre à la réponse HTTP.
        // Le dédoublonnage par forme est actif par défaut ; on ne le désactive
        // que lorsque l'appelant le demande explicitement.
        $environment = '';
        if (array_key_exists('dedup', $arguments) && $arguments['dedup'] === false) {
            $environment = 'CRAWL_DEDUP_SHAPE=0 ';
        }

        exec($environment . sprintf(
            'nohup php %s app:crawl %d >> %s 2>&1 < /dev/null & echo $! > %s',
            escapeshellarg($console),
            $id,
            escapeshellarg($log),
            escapeshellarg($pidFile)
        ));

        return [
            'message' => 'Crawl triggered successfully',
            'siteId' => $id,
            'log' => $log,
            'started' => true,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function status(array $arguments, ?McpApiKey $apiKey): array
    {
        $siteId = $arguments['siteId'] ?? null;

        if (!$siteId) {
            throw new \InvalidArgumentException('siteId is required');
        }

        $site = $this->em->getRepository(Site::class)->find($siteId);

        if (!$site) {
            throw new \InvalidArgumentException("Site not found: {$siteId}");
        }

        $id = (int) $site->getId();
        $log = sys_get_temp_dir() . "/optisight_crawl_{$id}.log";
        $metadata = $site->getCrawlMetadata() ?? [];

        $result = [
            'siteId' => $id,
            'rootUrl' => $site->getRootUrl(),
            'crawlStatus' => $site->getCrawlStatus(),
            'crawledPages' => $site->getCrawledPages(),
            'lastCrawledAt' => $site->getLastCrawledAt()?->format('Y-m-d H:i:s'),
            'metadataPages' => count($metadata),
        ];

        if (is_file($log)) {
            $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $result['logMissing'] = false;
            $result['log'] = array_slice($lines, -50);
            $result['discarded'] = $this->parseDiscarded($lines);
        } else {
            $result['logMissing'] = true;
            $result['log'] = [];
            $result['discarded'] = [];
        }

        return $result;
    }

    /**
     * Extrait des lignes « SKIP:<url>:<motif> », les 50 dernières.
     *
     * @param string[] $lines
     * @return array<int, array{url: string, reason: string}>
     */
    private function parseDiscarded(array $lines): array
    {
        $discarded = [];

        foreach ($lines as $line) {
            if (preg_match('/^SKIP:(.+):([^:]+)$/', trim($line), $matches) === 1) {
                $discarded[] = [
                    'url' => $matches[1],
                    'reason' => $matches[2],
                ];
            }
        }

        return array_slice($discarded, -50);
    }
}
