<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\AiSummary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;

#[Route('/audit/analysis')]
class AnalysisController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

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
        ]);
    }
}
