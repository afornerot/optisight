<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\AiSummary;
use App\Service\AiService;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;

#[Route('/audit/analysis')]
class AnalysisController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiService $ai,
        private LighthouseService $lighthouse,
        private Pa11yService $pa11y,
    ) {}

    #[Route('/{id}', name: 'audit_analysis')]
    public function show(int $id): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $reports = $this->em->getRepository(PageReport::class)
            ->findBy(['analysis' => $analysis], ['url' => 'ASC']);

        $summary = $this->em->getRepository(AiSummary::class)
            ->findOneBy(['analysis' => $analysis], ['createdAt' => 'DESC']);

        $normalizedSummary = null;
        if ($summary) {
            $normalizedSummary = [
                'summary' => is_string($summary->getSummary()) ? $summary->getSummary() : $this->normalizeToText($summary->getSummary()),
                'summaryJson' => $summary->getSummaryJson() ?? [],
                'recommendations' => array_map(function ($rec) {
                    if (isset($rec['details']) && is_array($rec['details'])) {
                        $rec['details'] = $this->formatDetails($rec['details']);
                    }
                    if (!isset($rec['pages']) || !is_array($rec['pages'])) {
                        $rec['pages'] = [];
                    }
                    return $rec;
                }, $summary->getRecommendations() ?? []),
            ];
        }

        return $this->render('audit/analysis.html.twig', [
            'analysis' => $analysis,
            'site' => $analysis->getSite(),
            'reports' => $reports,
            'summary' => $normalizedSummary,
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/progress', name: 'audit_analysis_progress')]
    public function progress(int $id): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        return $this->render('audit/progress.html.twig', [
            'analysis' => $analysis,
            'site' => $analysis->getSite(),
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/ai', name: 'audit_analysis_ai')]
    public function ai(int $id): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        return $this->render('audit/ai.html.twig', [
            'analysis' => $analysis,
            'site' => $analysis->getSite(),
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/status', name: 'audit_analysis_status', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $reportCount = $this->em->getRepository(PageReport::class)
            ->count(['analysis' => $analysis]);

        $summary = $this->em->getRepository(AiSummary::class)
            ->findOneBy(['analysis' => $analysis], ['createdAt' => 'DESC']);

        return new JsonResponse([
            'status' => $analysis->getStatus(),
            'pagesCrawled' => $analysis->getPagesCrawled(),
            'totalPages' => $analysis->getTotalPages(),
            'reportCount' => $reportCount,
            'progress' => $analysis->getProgress(),
            'errorMessage' => $analysis->getErrorMessage(),
            'startedAt' => $analysis->getStartedAt()?->format('H:i:s'),
            'duration' => $analysis->getDuration(),
            'hasAiSummary' => $summary !== null,
        ]);
    }

    #[Route('/{id}/stop', name: 'audit_analysis_stop', methods: ['POST'])]
    public function stop(int $id): JsonResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        if ($analysis->getStatus() !== Analysis::STATUS_RUNNING) {
            return new JsonResponse(['error' => 'Not running'], 400);
        }

        $analysis->setStatus(Analysis::STATUS_CANCELLED);
        $analysis->setErrorMessage('Arrêté par l\'utilisateur');
        $analysis->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/{id}/delete', name: 'audit_analysis_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $siteId = $analysis->getSite()->getId();
        $this->em->remove($analysis);
        $this->em->flush();

        $this->addFlash('success', 'Analyse supprimée.');
        return $this->redirectToRoute('audit_site', ['id' => $siteId]);
    }

    #[Route('/{id}/pdf', name: 'audit_analysis_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $reports = $this->em->getRepository(PageReport::class)
            ->findBy(['analysis' => $analysis], ['url' => 'ASC']);

        $summary = $this->em->getRepository(AiSummary::class)
            ->findOneBy(['analysis' => $analysis], ['createdAt' => 'DESC']);

        $normalizedSummary = null;
        if ($summary) {
            $normalizedSummary = [
                'summary' => is_string($summary->getSummary()) ? $summary->getSummary() : $this->normalizeToText($summary->getSummary()),
                'summaryJson' => $summary->getSummaryJson() ?? [],
                'recommendations' => array_map(function ($rec) {
                    if (isset($rec['details']) && is_array($rec['details'])) {
                        $rec['details'] = $this->formatDetails($rec['details']);
                    }
                    if (!isset($rec['pages']) || !is_array($rec['pages'])) {
                        $rec['pages'] = [];
                    }
                    return $rec;
                }, $summary->getRecommendations() ?? []),
            ];
        }

        $html = $this->renderView('audit/pdf_report.html.twig', [
            'analysis' => $analysis,
            'site' => $analysis->getSite(),
            'reports' => $reports,
            'summary' => $normalizedSummary,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('rapport-audit-%s-%s.pdf',
            $analysis->getSite()->getName(),
            $analysis->getCreatedAt()->format('Y-m-d')
        );

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            ]
        );
    }

    private function normalizeToText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $v) {
                $parts[] = "{$key}: " . (is_array($v) ? implode(', ', $v) : $v);
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

    #[Route('/{id}/page/{reportId}', name: 'audit_analysis_page', methods: ['GET'])]
    public function page(int $id, int $reportId): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $report = $this->em->getRepository(PageReport::class)->find($reportId);
        if (!$report || $report->getAnalysis()->getId() !== $id) {
            throw $this->createNotFoundException('Rapport non trouvé');
        }

        return $this->render('audit/page.html.twig', [
            'analysis' => $analysis,
            'site' => $analysis->getSite(),
            'report' => $report,
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/page/{reportId}/ai', name: 'audit_analysis_page_ai', methods: ['POST'])]
    public function pageAi(int $id, int $reportId): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $report = $this->em->getRepository(PageReport::class)->find($reportId);
        if (!$report || $report->getAnalysis()->getId() !== $id) {
            throw $this->createNotFoundException('Rapport non trouvé');
        }

        $isXhr = $this->container->get('request_stack')->getCurrentRequest()->headers->get('X-Requested-With') === 'XMLHttpRequest';

        if ($isXhr) {
            $pageData = $this->buildPageData($report);

            return new StreamedResponse(function () use ($pageData, $report) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                ini_set('output_buffering', '0');
                ini_set('zlib.output_compression', '0');
                ob_implicit_flush(true);

                $results = [];
                $indicators = \App\Service\AiService::INDICATORS;
                $labels = \App\Service\AiService::INDICATOR_LABELS;

                foreach ($indicators as $idx => $indicator) {
                    $num = $idx + 1;
                    $label = $labels[$indicator];
                    echo "STEP:({$num}/5) Analyse {$label}...\n";
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();

                    $result = $this->ai->analyzePageByIndicator($pageData, $indicator);

                    if ($result) {
                        $results[$indicator] = $result;
                        echo "DONE_" . strtoupper($indicator) . ":OK\n";
                    } else {
                        $results[$indicator] = ['analysis' => '', 'recommendations' => []];
                        echo "DONE_" . strtoupper($indicator) . ":EMPTY\n";
                    }

                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();

                    usleep(1500000);
                }

                $report->setAiAnalysis($results);
                $report->setAiAnalysisAt(new \DateTimeImmutable());
                $this->em->flush();

                echo "DONE:Analyse IA terminée (5 indicateurs).\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            }, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache',
            ]);
        }

        $pageData = $this->buildPageData($report);
        $results = [];
        foreach (\App\Service\AiService::INDICATORS as $indicator) {
            $result = $this->ai->analyzePageByIndicator($pageData, $indicator);
            $results[$indicator] = $result ?? ['analysis' => '', 'recommendations' => []];
        }
        $report->setAiAnalysis($results);
        $report->setAiAnalysisAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Analyse IA terminée pour cette page (5 indicateurs).');

        return $this->redirectToRoute('audit_analysis_page', ['id' => $id, 'reportId' => $reportId]);
    }

    #[Route('/{id}/page/{reportId}/reanalyze', name: 'audit_analysis_page_reanalyze', methods: ['POST'])]
    public function pageReanalyze(int $id, int $reportId): Response
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            throw $this->createNotFoundException('Analyse non trouvée');
        }

        $report = $this->em->getRepository(PageReport::class)->find($reportId);
        if (!$report || $report->getAnalysis()->getId() !== $id) {
            throw $this->createNotFoundException('Rapport non trouvé');
        }

        $isXhr = $this->container->get('request_stack')->getCurrentRequest()->headers->get('X-Requested-With') === 'XMLHttpRequest';

        if ($isXhr) {
            $url = $report->getUrl();
            $cookieHeader = $analysis->getSite()->getCookieHeader();

            return new StreamedResponse(function () use ($url, $cookieHeader, $report) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                ini_set('output_buffering', '0');
                ini_set('zlib.output_compression', '0');
                ob_implicit_flush(true);

                echo "STEP:Lancement de Lighthouse sur {$url}...\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();

                $lhResult = $this->lighthouse->analyze($url, $cookieHeader);

                if ($lhResult) {
                    $report->setLhPerformance($lhResult['performance']);
                    $report->setLhAccessibility($lhResult['accessibility']);
                    $report->setLhSeo($lhResult['seo']);
                    $report->setLhBestPractices($lhResult['bestPractices']);
                    $report->setLighthouseReport($lhResult['raw']);
                    $this->em->flush();
                    echo "DONE_LH:Perf={$lhResult['performance']} A11y={$lhResult['accessibility']} SEO={$lhResult['seo']} BP={$lhResult['bestPractices']}\n";
                } else {
                    echo "ERROR_LH:Échec de Lighthouse.\n";
                }
                if (ob_get_level() > 0) { ob_flush(); }
                flush();

                echo "STEP:Lancement de pa11y sur {$url}...\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();

                $paResult = $this->pa11y->analyze($url, $cookieHeader);

                if ($paResult) {
                    $report->setRgaaScore($paResult['score']);
                    $report->setRgaaErrors($paResult['errors']);
                    $report->setRgaaWarnings($paResult['warnings']);
                    $report->setPa11yReport($paResult['raw']);
                    $this->em->flush();
                    echo "DONE_RGAA:Score={$paResult['score']} Erreurs=" . count($paResult['errors']) . " Avertissements=" . count($paResult['warnings']) . "\n";
                } else {
                    echo "ERROR_RGAA:Échec de pa11y.\n";
                }
                if (ob_get_level() > 0) { ob_flush(); }
                flush();

                $report->setAiAnalysis(null);
                $report->setAiAnalysisAt(null);
                $this->em->flush();

                echo "DONE:Analyse terminée. Les scores et rapports ont été mis à jour.\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            }, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache',
            ]);
        }

        return $this->redirectToRoute('audit_analysis_page', ['id' => $id, 'reportId' => $reportId]);
    }

    private function buildPageData(PageReport $report): array
    {
        return [
            'url' => $report->getUrl(),
            'lhPerformance' => $report->getLhPerformance(),
            'lhAccessibility' => $report->getLhAccessibility(),
            'lhSeo' => $report->getLhSeo(),
            'lhBestPractices' => $report->getLhBestPractices(),
            'rgaaScore' => $report->getRgaaScore(),
            'rgaaErrors' => $report->getRgaaErrors() ?? [],
            'rgaaWarnings' => $report->getRgaaWarnings() ?? [],
            'seoTitle' => $report->getSeoTitle(),
            'seoDescription' => $report->getSeoDescription(),
            'seoH1Count' => $report->getSeoH1Count(),
            'seoCanonical' => $report->getSeoCanonical(),
            'lighthouseReport' => $report->getLighthouseReport(),
            'pa11yReport' => $report->getPa11yReport(),
        ];
    }
}
