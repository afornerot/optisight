<?php

namespace App\Command;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Service\AiService;
use Doctrine\ORM\EntityManagerInterface;
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
        private AiService $ai,
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

        $reports = $this->em->getRepository(PageReport::class)
            ->findBy(['analysis' => $analysis]);

        if (empty($reports)) {
            $output->writeln("ERROR:Aucun rapport trouvé pour l'analyse #{$id}. Lancez d'abord l'analyse RGAA.");
            return Command::FAILURE;
        }

        $output->writeln("TITLE:Synthèse IA");
        $output->writeln("STEP:Envoi des données au modèle IA...");
        $output->writeln("PAGES:" . count($reports));

        try {
            $pagesScores = [];
            $rgaaErrorAgg = [];
            $lhFailureAgg = [];
            $pa11yIssueAgg = [];

            foreach ($reports as $report) {
                $url = $report->getUrl();
                $path = parse_url($url, PHP_URL_PATH) ?: '/';
                $pagesScores[] = [
                    'path' => $path,
                    'lhPerf' => $report->getLhPerformance(),
                    'lhA11y' => $report->getLhAccessibility(),
                    'lhSeo' => $report->getLhSeo(),
                    'lhBp' => $report->getLhBestPractices(),
                    'rgaa' => $report->getRgaaScore(),
                ];
                $output->writeln("PAGE_DATA:{$url}");

                $rgaaErrors = $report->getRgaaErrors();
                if (is_array($rgaaErrors)) {
                    foreach ($rgaaErrors as $err) {
                        $criterion = $err['criterion'] ?? $err['test'] ?? 'unknown';
                        if (!isset($rgaaErrorAgg[$criterion])) {
                            $rgaaErrorAgg[$criterion] = ['count' => 0, 'pages' => [], 'sample' => ''];
                        }
                        $rgaaErrorAgg[$criterion]['count']++;
                        if (!in_array($path, $rgaaErrorAgg[$criterion]['pages'], true)) {
                            $rgaaErrorAgg[$criterion]['pages'][] = $path;
                        }
                        if (empty($rgaaErrorAgg[$criterion]['sample']) && !empty($err['message'])) {
                            $rgaaErrorAgg[$criterion]['sample'] = mb_substr($err['message'], 0, 100);
                        }
                    }
                }

                $lhReport = $report->getLighthouseReport();
                if (is_array($lhReport) && isset($lhReport['audits'])) {
                    foreach ($lhReport['audits'] as $key => $audit) {
                        if (isset($audit['score']) && $audit['score'] < 1) {
                            if (!isset($lhFailureAgg[$key])) {
                                $lhFailureAgg[$key] = [
                                    'title' => $audit['title'] ?? $key,
                                    'count' => 0,
                                    'displayValue' => $audit['displayValue'] ?? '',
                                ];
                            }
                            $lhFailureAgg[$key]['count']++;
                        }
                    }
                }

                $pa11y = $report->getPa11yReport();
                if (is_array($pa11y)) {
                    foreach ($pa11y as $issue) {
                        $code = $issue['code'] ?? 'unknown';
                        if (!isset($pa11yIssueAgg[$code])) {
                            $pa11yIssueAgg[$code] = [
                                'type' => $issue['type'] ?? 'unknown',
                                'count' => 0,
                                'sample' => mb_substr($issue['message'] ?? '', 0, 100),
                            ];
                        }
                        $pa11yIssueAgg[$code]['count']++;
                    }
                }
            }

            uasort($rgaaErrorAgg, fn($a, $b) => $b['count'] <=> $a['count']);
            uasort($lhFailureAgg, fn($a, $b) => $b['count'] <=> $a['count']);
            uasort($pa11yIssueAgg, fn($a, $b) => $b['count'] <=> $a['count']);

            $rgaaErrorAgg = array_slice($rgaaErrorAgg, 0, 20, true);
            $lhFailureAgg = array_slice($lhFailureAgg, 0, 20, true);
            $pa11yIssueAgg = array_slice($pa11yIssueAgg, 0, 15, true);

            $pagesData = [
                'scores' => $pagesScores,
                'rgaa_errors_aggregated' => $rgaaErrorAgg,
                'lighthouse_failures_aggregated' => $lhFailureAgg,
                'pa11y_issues_aggregated' => $pa11yIssueAgg,
            ];

            $output->writeln("STEP:Analyse en cours (5 indicateurs)...");

            $allResults = [];
            $indicators = \App\Service\AiService::INDICATORS;
            $labels = \App\Service\AiService::INDICATOR_LABELS;

            foreach ($indicators as $idx => $indicator) {
                $num = $idx + 1;
                $label = $labels[$indicator];
                $output->writeln("STEP:({$num}/5) Analyse {$label}...");

                $aiResult = $this->ai->synthesizeReportByIndicator($pagesData, $indicator);

                if ($aiResult) {
                    $allResults[$indicator] = $aiResult;
                    $output->writeln("DONE_" . strtoupper($indicator) . ":OK");
                } else {
                    $allResults[$indicator] = ['summary' => '', 'recommendations' => []];
                    $output->writeln("DONE_" . strtoupper($indicator) . ":EMPTY");
                }

                if ($idx < count($indicators) - 1) {
                    usleep(1500000);
                }
            }

            if (!empty(array_filter($allResults, fn($r) => !empty($r['summary']) || !empty($r['recommendations'])))) {
                $summary = new AiSummary();
                $summary->setAnalysis($analysis);

                $globalSummary = '';
                $allRecommendations = [];
                foreach ($allResults as $indicator => $r) {
                    if (!empty($r['summary'])) {
                        $label = $labels[$indicator];
                        $globalSummary .= "**{$label}** : " . $r['summary'] . "\n\n";
                    }
                    if (!empty($r['recommendations'])) {
                        $allRecommendations = array_merge($allRecommendations, $r['recommendations']);
                    }
                }

                $summary->setSummary(trim($globalSummary));
                $summary->setRecommendations($allRecommendations);
                $summary->setSummaryJson($allResults);
                $this->em->persist($summary);
                $this->em->flush();

                $output->writeln("SUMMARY:" . trim($globalSummary));

                if (!empty($allRecommendations)) {
                    foreach ($allRecommendations as $rec) {
                        $priority = $rec['priority'] ?? 'LOW';
                        $category = $rec['category'] ?? '';
                        $title = $rec['title'] ?? '';
                        $details = is_string($rec['details'] ?? '') ? ($rec['details'] ?? '') : $this->formatDetails($rec['details'] ?? []);
                        $pages = $rec['pages'] ?? [];
                        $pagesStr = !empty($pages) ? implode(',', $pages) : '';
                        $output->writeln("REC:{$priority}|{$category}|{$title}|{$details}|{$pagesStr}");
                    }
                }

                $output->writeln("DONE:Synthèse IA terminée (5 indicateurs).");
            } else {
                $output->writeln("WARN:Aucun résultat IA obtenu (clé API non configurée ?).");
            }
        } catch (\Exception $e) {
            $output->writeln("ERROR:Échec de la synthèse IA : {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function normalizeToText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $v) {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $parts[] = "{$key}: {$v}";
            }
            return implode('. ', $parts);
        }
        return (string) $value;
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
