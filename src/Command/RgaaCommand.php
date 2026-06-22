<?php

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:rgaa', description: 'Run RGAA/Lighthouse analysis on crawled pages')]
class RgaaCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private LighthouseService $lighthouse,
        private Pa11yService $pa11y,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('analysis_id', InputArgument::REQUIRED, 'Analysis ID to run RGAA on');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('analysis_id');
        $analysis = $this->em->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            $output->writeln("ERROR:Analyse #{$id} introuvable.");
            return Command::FAILURE;
        }

        if ($analysis->getStatus() === Analysis::STATUS_RUNNING) {
            $output->writeln("INFO:Analyse déjà en cours.");
            return Command::SUCCESS;
        }

        $site = $analysis->getSite();
        $crawlMetadata = $site->getCrawlMetadata();

        if (empty($crawlMetadata)) {
            $output->writeln("ERROR:Aucune donnée de crawl pour \"{$site->getName()}\". Veuillez d'abord crawler le site.");
            return Command::FAILURE;
        }

        $analysis->setStatus(Analysis::STATUS_RUNNING);
        $analysis->setStartedAt(new \DateTimeImmutable());
        $analysis->setTotalPages(count($crawlMetadata));
        $this->em->flush();

        $output->writeln("TITLE:Analyse RGAA + Lighthouse");
        $output->writeln("SITE:{$site->getName()}");
        $output->writeln("PAGES:" . count($crawlMetadata));
        $output->writeln("STEP:Analyse en cours...");

        try {
            $total = count($crawlMetadata);

            foreach ($crawlMetadata as $i => $page) {
                $num = $i + 1;

                $this->em->refresh($analysis);
                if ($analysis->getStatus() === Analysis::STATUS_CANCELLED) {
                    $output->writeln("INFO:Analyse annulée par l'utilisateur.");
                    return Command::SUCCESS;
                }

                $output->writeln("PAGE_START:{$num}:{$total}:{$page['url']}");

                $report = new PageReport();
                $report->setAnalysis($analysis);
                $report->setUrl($page['url']);
                $report->setHttpStatus($page['status'] ?? null);
                $report->setSeoTitle($page['title'] ?? null);
                $report->setSeoDescription($page['metaDescription'] ?? null);
                $report->setSeoH1Count($page['h1Count'] ?? null);
                $report->setSeoCanonical($page['canonical'] ?? null);
                $report->setCrawlMetadata([
                    'depth' => $page['depth'] ?? null,
                ]);

                $lhResult = $this->lighthouse->analyze($page['url']);
                if ($lhResult) {
                    $report->setLhPerformance($lhResult['performance']);
                    $report->setLhAccessibility($lhResult['accessibility']);
                    $report->setLhSeo($lhResult['seo']);
                    $report->setLhBestPractices($lhResult['bestPractices']);
                    $report->setLighthouseReport($lhResult['raw']);
                    $output->writeln("LH:{$lhResult['performance']}:{$lhResult['accessibility']}:{$lhResult['seo']}");
                } else {
                    $output->writeln("LH:NONE");
                }

                $paResult = $this->pa11y->analyze($page['url']);
                if ($paResult) {
                    $errors = count($paResult['errors']);
                    $warnings = count($paResult['warnings']);
                    $report->setRgaaScore($paResult['score']);
                    $report->setRgaaErrors($paResult['errors']);
                    $report->setRgaaWarnings($paResult['warnings']);
                    $report->setPa11yReport($paResult['raw']);
                    $output->writeln("RGAA:{$paResult['score']}:{$errors}:{$warnings}");
                } else {
                    $output->writeln("RGAA:NONE");
                }

                $this->em->persist($report);
                $this->em->flush();

                $analysis->setPagesCrawled($num);
                $this->em->flush();
            }

            $this->em->refresh($analysis);
            if ($analysis->getStatus() !== Analysis::STATUS_CANCELLED) {
                $analysis->setStatus(Analysis::STATUS_COMPLETED);
                $analysis->setCompletedAt(new \DateTimeImmutable());
                $this->em->flush();
            }

            $output->writeln("DONE:{$total} page(s) analysée(s).");
        } catch (\Exception $e) {
            $this->em->clear();
            $analysis = $this->em->getRepository(Analysis::class)->find($id);
            if ($analysis) {
                $analysis->setStatus(Analysis::STATUS_FAILED);
                $analysis->setErrorMessage($e->getMessage());
                $this->em->flush();
            }
            $output->writeln("ERROR:Échec de l'analyse : {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
