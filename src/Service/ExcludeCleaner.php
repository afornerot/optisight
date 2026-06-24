<?php

namespace App\Service;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

class ExcludeCleaner
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function cleanSite(Site $site): int
    {
        $excludePatterns = $site->getExcludePatterns();
        if (empty($excludePatterns)) {
            return 0;
        }

        $analyses = $this->em->getRepository(Analysis::class)
            ->findBy(['site' => $site]);

        $deleted = 0;
        foreach ($analyses as $analysis) {
            $deleted += $this->cleanAnalysis($analysis);
        }

        $crawlMetadata = $site->getCrawlMetadata() ?? [];
        $cleanedMetadata = [];
        foreach ($crawlMetadata as $page) {
            if (!$site->isUrlExcluded($page['url'] ?? '')) {
                $cleanedMetadata[] = $page;
            }
        }
        $site->setCrawlMetadata($cleanedMetadata);
        $site->setCrawledPages(count($cleanedMetadata));
        $this->em->flush();

        return $deleted;
    }

    public function cleanAnalysis(Analysis $analysis): int
    {
        $site = $analysis->getSite();
        $reports = $this->em->getRepository(PageReport::class)
            ->findBy(['analysis' => $analysis]);

        $deleted = 0;
        $kept = 0;

        foreach ($reports as $report) {
            if ($site->isUrlExcluded($report->getUrl())) {
                $this->em->remove($report);
                $deleted++;
            } else {
                $kept++;
            }
        }

        $analysis->setPagesCrawled($kept);
        $analysis->setTotalPages($kept);

        return $deleted;
    }
}
