<?php

namespace App\Mcp\Resources;

use App\Entity\McpApiKey;
use App\Repository\SiteRepository;

class SiteMcpResource
{
    private SiteRepository $siteRepository;

    public function __construct(SiteRepository $siteRepository)
    {
        $this->siteRepository = $siteRepository;
    }

    public function list(array $arguments, ?McpApiKey $apiKey): array
    {
        $sites = $this->siteRepository->findBy([], ['createdAt' => 'DESC']);

        $result = [];
        foreach ($sites as $site) {
            $lastAnalysis = $site->getAnalyses()->first() ?: null;
            $result[] = [
                'id' => $site->getId(),
                'name' => $site->getName(),
                'rootUrl' => $site->getRootUrl(),
                'prodUrl' => $site->getProdUrl(),
                'authType' => $site->getAuthType(),
                'lastAnalysis' => $lastAnalysis ? [
                    'id' => $lastAnalysis->getId(),
                    'status' => $lastAnalysis->getStatus(),
                    'createdAt' => $lastAnalysis->getCreatedAt()?->format('Y-m-d H:i:s'),
                    'pagesCrawled' => $lastAnalysis->getPagesCrawled(),
                    'totalPages' => $lastAnalysis->getTotalPages(),
                ] : null,
                'createdAt' => $site->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'sites' => $result,
            'count' => count($result),
        ];
    }

    public function get(array $arguments, ?McpApiKey $apiKey): array
    {
        $siteId = $arguments['siteId'] ?? null;

        if (!$siteId) {
            throw new \InvalidArgumentException('siteId is required');
        }

        $site = $this->siteRepository->find($siteId);

        if (!$site) {
            throw new \InvalidArgumentException("Site not found: {$siteId}");
        }

        $analyses = [];
        foreach ($site->getAnalyses() as $analysis) {
            $analyses[] = [
                'id' => $analysis->getId(),
                'status' => $analysis->getStatus(),
                'createdAt' => $analysis->getCreatedAt()?->format('Y-m-d H:i:s'),
                'pagesCrawled' => $analysis->getPagesCrawled(),
                'totalPages' => $analysis->getTotalPages(),
                'duration' => $analysis->getDuration(),
            ];
        }

        return [
            'id' => $site->getId(),
            'name' => $site->getName(),
            'rootUrl' => $site->getRootUrl(),
            'prodUrl' => $site->getProdUrl(),
            'authType' => $site->getAuthType(),
            'authCookies' => $site->getAuthCookies() ? '***' : null,
            'createdAt' => $site->getCreatedAt()?->format('Y-m-d H:i:s'),
            'analyses' => $analyses,
        ];
    }
}
