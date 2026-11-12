<?php

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Service\AiSynthesisService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai', description: 'Generate AI synthesis from analysis page reports')]
class AiCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiSynthesisService $synthesis,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('analysis_id', InputArgument::REQUIRED, 'Analysis ID to synthesize');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('analysis_id');
        $analysis = $this->em->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            $output->writeln("ERROR:Analyse #{$id} introuvable.");
            return Command::FAILURE;
        }

        $reports = $this->synthesis->reports($analysis);

        if (empty($reports)) {
            $output->writeln("ERROR:Aucun rapport trouvé pour l'analyse #{$id}. Lancez d'abord l'analyse RGAA.");
            return Command::FAILURE;
        }

        $output->writeln("TITLE:Synthèse IA");
        $output->writeln("STEP:Envoi des données au modèle IA...");
        $output->writeln("PAGES:" . count($reports));
        $this->logger->info('AI synthesis started', ['analysis_id' => $id, 'reports_count' => count($reports)]);

        try {
            $pagesData = $this->synthesis->buildPagesData($reports, static function (PageReport $report) use ($output): void {
                $output->writeln("PAGE_DATA:" . $report->getUrl());
            });

            $output->writeln("STEP:Analyse en cours (5 indicateurs)...");

            $summary = $this->synthesis->synthesize(
                $analysis,
                $pagesData,
                static function (string $indicator, int $num, string $label) use ($output): void {
                    $output->writeln("STEP:({$num}/5) Analyse {$label}...");
                },
                static function (string $indicator, ?array $result) use ($output): void {
                    $output->writeln("DONE_" . strtoupper($indicator) . ($result !== null ? ':OK' : ':EMPTY'));
                }
            );

            if ($summary === null) {
                $output->writeln("WARN:Aucun résultat IA obtenu (clé API non configurée ?).");
                return Command::SUCCESS;
            }

            $output->writeln("SUMMARY:" . $summary->getSummary());

            foreach ($summary->getRecommendations() ?? [] as $rec) {
                $priority = $rec['priority'] ?? 'LOW';
                $category = $rec['category'] ?? '';
                $title = $rec['title'] ?? '';

                // callLlm aplatit déjà les "details" en chaîne ; on garde le
                // repli pour les résumés enregistrés avant cette normalisation.
                $rawDetails = $rec['details'] ?? '';
                $details = is_string($rawDetails)
                    ? $rawDetails
                    : $this->formatDetails(is_array($rawDetails) ? $rawDetails : []);

                $pages = $rec['pages'] ?? [];
                $pagesStr = !empty($pages) ? implode(',', $pages) : '';
                $output->writeln("REC:{$priority}|{$category}|{$title}|{$details}|{$pagesStr}");
            }

            $output->writeln("DONE:Synthèse IA terminée (5 indicateurs).");
        } catch (\Exception $e) {
            $this->logger->error('AI synthesis failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $output->writeln("ERROR:Échec de la synthèse IA : {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
