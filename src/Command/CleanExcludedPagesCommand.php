<?php

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\PageReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:clean-excluded', description: 'Remove pages that match exclusion rules from an analysis')]
class CleanExcludedPagesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('analysis_id', InputArgument::REQUIRED, 'Analysis ID to clean');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('analysis_id');
        $analysis = $this->em->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            $output->writeln("<error>Analysis #{$id} not found</error>");
            return Command::FAILURE;
        }

        $site = $analysis->getSite();
        $reports = $this->em->getRepository(PageReport::class)
            ->findBy(['analysis' => $analysis]);

        $excludePatterns = $site->getExcludePatterns();
        if (empty($excludePatterns)) {
            $output->writeln("No exclusion rules configured for this site. Nothing to clean.");
            return Command::SUCCESS;
        }

        $output->writeln("Site: {$site->getName()}");
        $output->writeln("Exclusion rules: " . count($excludePatterns));
        foreach ($excludePatterns as $p) {
            $output->writeln("  - {$p['type']}: {$p['value']}");
        }
        $output->writeln("Total reports: " . count($reports));
        $output->writeln("");

        $deleted = 0;
        $kept = 0;

        foreach ($reports as $report) {
            if ($site->isUrlExcluded($report->getUrl())) {
                $output->writeln("EXCLUDED: {$report->getUrl()}");
                $this->em->remove($report);
                $deleted++;
            } else {
                $kept++;
            }
        }

        $this->em->flush();

        $analysis->setPagesCrawled($kept);
        $this->em->flush();

        $output->writeln("");
        $output->writeln("Done: {$deleted} page(s) supprimée(s), {$kept} page(s) conservée(s).");

        return Command::SUCCESS;
    }
}
