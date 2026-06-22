<?php

namespace App\Controller;

use App\Entity\Site;
use App\Entity\Analysis;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/audit')]
class SiteController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', name: 'audit_index')]
    public function index(): Response
    {
        $sites = $this->em->getRepository(Site::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('audit/index.html.twig', [
            'sites' => $sites,
        ]);
    }

    #[Route('/new', name: 'audit_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $site = new Site();
            $site->setName($request->request->get('name'));
            $site->setRootUrl($request->request->get('root_url'));

            $this->em->persist($site);
            $this->em->flush();

            $this->addFlash('success', 'Site ajouté avec succès.');
            return $this->redirectToRoute('audit_site', ['id' => $site->getId()]);
        }

        return $this->render('audit/new.html.twig');
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

        return $this->render('audit/site.html.twig', [
            'site' => $site,
            'analyses' => $analyses,
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
        ]);
    }
}
