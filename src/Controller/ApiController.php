<?php

namespace App\Controller;

use App\Entity\AiSummary;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Annotation as ApiDoc;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/v1/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_index', methods: ['GET'])]
    #[ApiDoc\Area(['default'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'iargaaseo API',
            'version' => '1.0.0',
            'endpoints' => [
                'GET /v1/api' => 'This endpoint',
                'GET /v1/api/audit' => 'Liste des sites avec scores et synthèse IA',
            ],
        ]);
    }

    #[Route('/audit', name: 'api_audit', methods: ['GET'])]
    #[ApiDoc\Area(['default'])]
    #[OA\Tag(name: 'Audit')]
    public function audit(): JsonResponse
    {
        $sites = $this->em->getRepository(Site::class)->findBy([], ['createdAt' => 'DESC']);

        $result = [];
        foreach ($sites as $site) {
            $lastAnalysis = $this->em->getRepository(Analysis::class)
                ->findOneBy(['site' => $site, 'status' => 'completed'], ['createdAt' => 'DESC']);

            $scores = null;
            $analysisDate = null;
            $analysisId = null;

            if ($lastAnalysis) {
                $reports = $this->em->getRepository(PageReport::class)
                    ->findBy(['analysis' => $lastAnalysis]);

                $count = count($reports);
                if ($count > 0) {
                    $avgRgaa = 0;
                    $avgA11y = 0;
                    $avgPerf = 0;
                    $avgBp = 0;
                    $avgSeo = 0;
                    foreach ($reports as $r) {
                        $avgRgaa += ($r->getRgaaScore() ?? 0);
                        $avgA11y += ($r->getLhAccessibility() ?? 0);
                        $avgPerf += ($r->getLhPerformance() ?? 0);
                        $avgBp += ($r->getLhBestPractices() ?? 0);
                        $avgSeo += ($r->getLhSeo() ?? 0);
                    }
                    $scores = [
                        'rgaa' => round($avgRgaa / $count),
                        'a11y' => round($avgA11y / $count),
                        'perf' => round($avgPerf / $count),
                        'bp' => round($avgBp / $count),
                        'seo' => round($avgSeo / $count),
                    ];
                }

                $analysisDate = $lastAnalysis->getCreatedAt()->format('c');
                $analysisId = $lastAnalysis->getId();
            }

            $summary = null;
            if ($lastAnalysis) {
                $aiSummary = $this->em->getRepository(AiSummary::class)
                    ->findOneBy(['analysis' => $lastAnalysis], ['createdAt' => 'DESC']);

                if ($aiSummary) {
                    $summary = [
                        'text' => $aiSummary->getSummary(),
                        'indicators' => $aiSummary->getSummaryJson() ?? [],
                        'recommendations' => $aiSummary->getRecommendations() ?? [],
                        'createdAt' => $aiSummary->getCreatedAt()->format('c'),
                    ];
                }
            }

            $result[] = [
                'id' => $site->getId(),
                'name' => $site->getName(),
                'rootUrl' => $site->getRootUrl(),
                'lastAnalysis' => $analysisId ? [
                    'id' => $analysisId,
                    'date' => $analysisDate,
                    'scores' => $scores,
                ] : null,
                'aiSummary' => $summary,
            ];
        }

        return $this->json($result);
    }
}
