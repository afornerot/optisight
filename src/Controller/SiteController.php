<?php

namespace App\Controller;

use App\Entity\Site;
use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\ExcludeCleaner;

#[Route('/audit')]
class SiteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuthService $authService,
        private ExcludeCleaner $cleaner,
    ) {}

    #[Route('', name: 'audit_index')]
    public function index(): Response
    {
        $sites = $this->em->getRepository(Site::class)->findBy([], ['createdAt' => 'DESC']);

        $siteScores = [];
        foreach ($sites as $site) {
            $lastAnalysis = $this->em->getRepository(Analysis::class)
                ->findOneBy(['site' => $site, 'status' => 'completed'], ['createdAt' => 'DESC']);

            if ($lastAnalysis) {
                $reports = $this->em->getRepository(PageReport::class)
                    ->findBy(['analysis' => $lastAnalysis]);

                $count = count($reports);
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
                $siteScores[$site->getId()] = [
                    'rgaa' => $count > 0 ? round($avgRgaa / $count) : null,
                    'a11y' => $count > 0 ? round($avgA11y / $count) : null,
                    'perf' => $count > 0 ? round($avgPerf / $count) : null,
                    'bp' => $count > 0 ? round($avgBp / $count) : null,
                    'seo' => $count > 0 ? round($avgSeo / $count) : null,
                ];
            }
        }

        return $this->render('audit/index.html.twig', [
            'sites' => $sites,
            'siteScores' => $siteScores,
            'usemenu' => true,
        ]);
    }

    #[Route('/new', name: 'audit_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $site = new Site();
            $site->setName($request->request->get('name'));
            $site->setRootUrl($request->request->get('root_url'));

            $prodUrl = $request->request->get('prod_url');
            if ($prodUrl) {
                $site->setProdUrl($prodUrl);
            }

            $this->saveExcludePatterns($site, $request);

            $this->em->persist($site);
            $this->em->flush();

            $this->addFlash('success', 'Site ajouté avec succès.');
            return $this->redirectToRoute('audit_site', ['id' => $site->getId()]);
        }

        return $this->render('audit/new.html.twig', [
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}', name: 'audit_site')]
    public function show(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $analyses = $this->em->getRepository(Analysis::class)
            ->findBy(['site' => $site], ['createdAt' => 'DESC']);

        $analysisScores = [];
        foreach ($analyses as $analysis) {
            if ($analysis->getStatus() !== 'completed') {
                continue;
            }
            $reports = $this->em->getRepository(PageReport::class)
                ->findBy(['analysis' => $analysis]);
            $count = count($reports);
            if ($count === 0) {
                continue;
            }
            $avgRgaa = $avgA11y = $avgPerf = $avgBp = $avgSeo = 0;
            foreach ($reports as $r) {
                $avgRgaa += ($r->getRgaaScore() ?? 0);
                $avgA11y += ($r->getLhAccessibility() ?? 0);
                $avgPerf += ($r->getLhPerformance() ?? 0);
                $avgBp += ($r->getLhBestPractices() ?? 0);
                $avgSeo += ($r->getLhSeo() ?? 0);
            }
            $analysisScores[$analysis->getId()] = [
                'rgaa' => round($avgRgaa / $count),
                'a11y' => round($avgA11y / $count),
                'perf' => round($avgPerf / $count),
                'bp'   => round($avgBp / $count),
                'seo'  => round($avgSeo / $count),
            ];
        }

        return $this->render('audit/site.html.twig', [
            'site' => $site,
            'analyses' => $analyses,
            'analysisScores' => $analysisScores,
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'audit_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if ($site) {
            $this->em->remove($site);
            $this->em->flush();
            $this->addFlash('success', 'Site supprimé.');
        }
        return $this->redirectToRoute('audit_index');
    }

    #[Route('/{id}/analyze', name: 'audit_analyze', methods: ['POST'])]
    public function analyze(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $analysis = new Analysis();
        $analysis->setSite($site);
        $this->em->persist($analysis);
        $this->em->flush();

        return $this->redirectToRoute('audit_analysis_progress', ['id' => $analysis->getId()]);
    }

    #[Route('/{id}/crawl', name: 'audit_crawl_page')]
    public function crawlPage(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        return $this->render('audit/crawl.html.twig', [
            'site' => $site,
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'audit_site_edit', methods: ['POST'])]
    public function edit(int $id, Request $request): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $site->setName($request->request->get('name'));
        $site->setRootUrl($request->request->get('root_url'));
        $site->setProdUrl($request->request->get('prod_url') ?: null);
        $this->em->flush();

        $this->addFlash('success', 'Informations du site mises à jour.');
        return $this->redirectToRoute('audit_site', ['id' => $id]);
    }

    #[Route('/{id}/exclude', name: 'audit_exclude', methods: ['POST'])]
    public function exclude(int $id, Request $request): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $this->saveExcludePatterns($site, $request);
        $this->em->flush();

        $this->addFlash('success', 'Règles d\'exclusion mises à jour.');
        return $this->redirectToRoute('audit_site', ['id' => $id]);
    }

    #[Route('/{id}/exclude/clean', name: 'audit_exclude_clean', methods: ['POST'])]
    public function excludeClean(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        if (empty($site->getExcludePatterns())) {
            $this->addFlash('warning', 'Aucune règle d\'exclusion configurée.');
            return $this->redirectToRoute('audit_site', ['id' => $id]);
        }

        $deleted = $this->cleaner->cleanSite($site);

        $this->addFlash('success', "{$deleted} page(s) supprimée(s) selon les règles d'exclusion.");
        return $this->redirectToRoute('audit_site', ['id' => $id]);
    }

    #[Route('/{id}/auth', name: 'audit_auth')]
    public function auth(int $id, Request $request): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        if ($request->isMethod('POST')) {
            $authType = $request->request->get('auth_type') ?: null;
            $site->setAuthType($authType);

            if ($authType === 'cookie') {
                $site->setAuthCookies($request->request->get('auth_cookies') ?: null);
                $site->setAuthLoginUrl(null);
                $site->setAuthUsernameField(null);
                $site->setAuthPasswordField(null);
                $site->setAuthUsername(null);
                $site->setAuthPassword(null);
            } elseif ($authType === 'form') {
                $site->setAuthLoginUrl($request->request->get('auth_login_url') ?: null);
                $site->setAuthUsernameField($request->request->get('auth_username_field') ?: null);
                $site->setAuthPasswordField($request->request->get('auth_password_field') ?: null);
                $site->setAuthUsername($request->request->get('auth_username') ?: null);
                $site->setAuthPassword($request->request->get('auth_password') ?: null);
            } else {
                $site->setAuthCookies(null);
                $site->setAuthLoginUrl(null);
                $site->setAuthUsernameField(null);
                $site->setAuthPasswordField(null);
                $site->setAuthUsername(null);
                $site->setAuthPassword(null);
            }

            $this->em->flush();
            $this->addFlash('success', 'Configuration d\'authentification enregistrée.');
            return $this->redirectToRoute('audit_auth', ['id' => $site->getId()]);
        }

        return $this->render('audit/auth.html.twig', [
            'site' => $site,
            'usemenu' => true,
        ]);
    }

    #[Route('/{id}/auth/login', name: 'audit_auth_login', methods: ['POST'])]
    public function authLogin(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        if ($site->getAuthType() !== 'form') {
            $this->addFlash('danger', 'Ce site n\'est pas configuré en mode formulaire.');
            return $this->redirectToRoute('audit_auth', ['id' => $id]);
        }

        $result = $this->authService->loginWithForm($site);

        if ($result['success'] && !empty($result['cookies'])) {
            $site->setAuthCookies(json_encode($result['cookies']));
            $this->em->flush();
            $this->addFlash('success', 'Connexion réussie ! ' . count($result['cookies']) . ' cookie(s) récupéré(s). URL finale : ' . ($result['finalUrl'] ?? ''));
        } else {
            $this->addFlash('danger', 'Échec de la connexion : ' . ($result['error'] ?? 'Erreur inconnue'));
        }

        return $this->redirectToRoute('audit_auth', ['id' => $id]);
    }

    #[Route('/{id}/auth/test', name: 'audit_auth_test', methods: ['POST'])]
    public function testAuth(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $result = $this->authService->testCookie($site);

        if ($result['success']) {
            $this->addFlash('success', "Connexion réussie (HTTP {$result['statusCode']}). Le cookie semble valide.");
        } else {
            $msg = $result['error'] ?? 'Erreur inconnue';
            if (isset($result['statusCode'])) {
                $msg = "Réponse HTTP {$result['statusCode']}. " . $msg;
            }
            $this->addFlash('danger', $msg);
        }

        return $this->redirectToRoute('audit_auth', ['id' => $id]);
    }

    private function saveExcludePatterns(Site $site, Request $request): void
    {
        $excludeTypes = $request->request->all('exclude_type');
        $excludeValues = $request->request->all('exclude_value');
        $excludePatterns = [];
        if (is_array($excludeTypes) && is_array($excludeValues)) {
            $count = min(count($excludeTypes), count($excludeValues));
            for ($i = 0; $i < $count; $i++) {
                $val = trim($excludeValues[$i]);
                if ($val !== '') {
                    $excludePatterns[] = [
                        'type' => $excludeTypes[$i],
                        'value' => $val,
                    ];
                }
            }
        }
        $site->setExcludePatterns(!empty($excludePatterns) ? $excludePatterns : null);
    }
}
