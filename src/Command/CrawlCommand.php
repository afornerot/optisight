<?php

namespace App\Command;

use App\Entity\Site;
use App\Service\CrawlerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:crawl', description: 'Crawl a site and store discovered pages')]
class CrawlCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrawlerService $crawler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site_id', InputArgument::REQUIRED, 'Site ID to crawl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('site_id');
        $site = $this->em->getRepository(Site::class)->find($id);

        if (!$site) {
            $output->writeln("ERROR:Site #{$id} introuvable.");
            return Command::FAILURE;
        }

        if ($site->getCrawlStatus() === Site::CRAWL_STATUS_RUNNING) {
            $output->writeln("INFO:Crawl déjà en cours pour ce site.");
            return Command::SUCCESS;
        }

        $site->setCrawlStatus(Site::CRAWL_STATUS_RUNNING);
        $this->em->flush();

        $output->writeln("TITLE:Crawl du site");
        $output->writeln("SITE:{$site->getName()}");
        $output->writeln("URL:{$site->getRootUrl()}");

        try {
            $pages = $this->crawler->crawl(
                $site->getRootUrl(),
                // onProgress
                function (int $crawled, int $total) use ($site) {
                    $site->setCrawledPages($crawled);
                    $this->em->flush();
                },
                // shouldStop
                function () use ($id) {
                    $site = $this->em->getRepository(Site::class)->find($id);
                    return $site && $site->getCrawlStatus() === Site::CRAWL_STATUS_CANCELLED;
                },
                // onPageFound — écrit en temps réel
                function (array $page) use ($output) {
                    if (isset($page['skipped'])) {
                        $output->writeln("SKIP:{$page['url']}:{$page['reason']}");
                    } else {
                        $output->writeln("FOUND:{$page['status']}:{$page['url']}:{$page['title']}");
                    }
                }
            );

            $pagesCount = count($pages);

            if ($pagesCount === 0) {
                $output->writeln("WARN:Aucune page HTML découverte.");
            } else {
                $output->writeln("DONE:{$pagesCount} page(s) découverte(s).");

                $crawlData = [];
                foreach ($pages as $page) {
                    $crawlData[] = [
                        'url' => $page['url'],
                        'status' => $page['status'],
                        'depth' => $page['depth'],
                        'title' => $page['title'],
                        'metaDescription' => $page['metaDescription'],
                        'h1Count' => $page['h1Count'],
                        'canonical' => $page['canonical'],
                    ];
                }

                $site->setCrawlStatus(Site::CRAWL_STATUS_COMPLETED);
                $site->setLastCrawledAt(new \DateTimeImmutable());
                $site->setCrawledPages($pagesCount);
                $site->setCrawlMetadata($crawlData);
                $this->em->flush();
            }
        } catch (\Exception $e) {
            $this->em->clear();
            $site = $this->em->getRepository(Site::class)->find($id);
            if ($site) {
                $site->setCrawlStatus(Site::CRAWL_STATUS_FAILED);
                $this->em->flush();
            }
            $output->writeln("ERROR:Échec du crawl : {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
