<?php

namespace App\Command;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Service\AiService;
use App\Service\CrawlerService;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:run-analysis', description: 'Run an audit analysis in background')]
class RunAnalysisCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrawlerService $crawler,
        private LighthouseService $lighthouse,
        private Pa11yService $pa11y,
        private AiService $ai,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('analysis_id', InputArgument::REQUIRED, 'Analysis ID to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('analysis_id');
        $analysis = $this->em->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            $output->writeln("<error>Analysis #{$id} not found</error>");
            return Command::FAILURE;
        }

        if ($analysis->getStatus() === Analysis::STATUS_RUNNING) {
            $output->writeln("Analysis #{$id} already running, skipping.");
            return Command::SUCCESS;
        }

        $analysis->setStatus(Analysis::STATUS_RUNNING);
        $analysis->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        $output->writeln("Starting analysis #{$id}...");

        $conn = $this->em->getConnection();

        try {
            $rootUrl = $analysis->getSite()->getRootUrl();
            $cookieHeader = $analysis->getSite()->getCookieHeader();

            $pages = $this->crawler->crawl(
                $rootUrl,
                function (int $crawled, int $total) use ($id, $conn) {
                    $conn->executeStatement(
                        'UPDATE audit_analysis SET pages_crawled = :crawled, total_pages = :total WHERE id = :id',
                        ['crawled' => $crawled, 'total' => $total, 'id' => $id]
                    );
                },
                function () use ($id, $conn) {
                    $status = $conn->fetchOne(
                        'SELECT status FROM audit_analysis WHERE id = :id',
                        ['id' => $id]
                    );
                    return $status === Analysis::STATUS_CANCELLED;
                },
                null,
                $cookieHeader
            );

            $output->writeln("Crawl done: " . count($pages) . " pages found.");

            $cookieHeader = $analysis->getSite()->getCookieHeader();

            $analysis->setTotalPages(count($pages));
            $this->em->flush();

            $pagesData = [];
            foreach ($pages as $i => $page) {
                $output->writeln("[" . ($i + 1) . "/" . count($pages) . "] " . $page['url']);

                $report = new PageReport();
                $report->setAnalysis($analysis);
                $report->setUrl($page['url']);
                $report->setHttpStatus($page['status']);
                $report->setSeoTitle($page['title']);
                $report->setSeoDescription($page['metaDescription']);
                $report->setSeoH1Count($page['h1Count']);
                $report->setSeoCanonical($page['canonical']);
                $report->setCrawlMetadata([
                    'depth' => $page['depth'],
                ]);

                $lhResult = $this->lighthouse->analyze($page['url'], $cookieHeader);
                if ($lhResult) {
                    $report->setLhPerformance($lhResult['performance']);
                    $report->setLhAccessibility($lhResult['accessibility']);
                    $report->setLhSeo($lhResult['seo']);
                    $report->setLhBestPractices($lhResult['bestPractices']);
                    $report->setLighthouseReport($lhResult['raw']);
                }

                $paResult = $this->pa11y->analyze($page['url'], $cookieHeader);
                if ($paResult) {
                    $report->setRgaaScore($paResult['score']);
                    $report->setRgaaErrors($paResult['errors']);
                    $report->setRgaaWarnings($paResult['warnings']);
                    $report->setPa11yReport($paResult['raw']);
                }

                $this->em->persist($report);
                $this->em->flush();

                $pagesData[] = [
                    'url' => $page['url'],
                    'lhPerformance' => $lhResult['performance'] ?? null,
                    'lhAccessibility' => $lhResult['accessibility'] ?? null,
                    'lhSeo' => $lhResult['seo'] ?? null,
                    'rgaaScore' => $paResult['score'] ?? null,
                ];
            }

            $aiResult = $this->ai->synthesizeReport($pagesData);
            if ($aiResult) {
                $summary = new AiSummary();
                $summary->setAnalysis($analysis);
                $summary->setSummary($aiResult['summary'] ?? '');
                $summary->setRecommendations($aiResult['recommendations'] ?? []);
                $this->em->persist($summary);
            }

            // Don't overwrite if user cancelled during AI synthesis
            $this->em->refresh($analysis);
            if ($analysis->getStatus() !== Analysis::STATUS_CANCELLED) {
                $analysis->setStatus(Analysis::STATUS_COMPLETED);
                $analysis->setCompletedAt(new \DateTimeImmutable());
                $analysis->setPagesCrawled(count($pages));
                $this->em->flush();
            }

            $output->writeln("Analysis #{$id} completed.");
        } catch (\Exception $e) {
            $this->em->clear();
            $analysis = $this->em->getRepository(Analysis::class)->find($id);
            if ($analysis) {
                $analysis->setStatus(Analysis::STATUS_FAILED);
                $analysis->setErrorMessage($e->getMessage());
                $this->em->flush();
            }
            $output->writeln("<error>Analysis #{$id} failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
