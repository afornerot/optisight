<?php

namespace App\Command;

use App\Service\ExcludeCleaner;
use App\Entity\Analysis;
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
        private ExcludeCleaner $cleaner,
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

        $deleted = $this->cleaner->cleanSite($site);

        $output->writeln("");
        $output->writeln("Done: {$deleted} page(s) supprimée(s).");
        $output->writeln("Crawl metadata: " . count($site->getCrawlMetadata() ?? []) . " page(s).");

        return Command::SUCCESS;
    }
}
