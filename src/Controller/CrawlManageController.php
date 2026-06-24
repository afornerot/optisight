<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\PageReport;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\ExcludeCleaner;

#[Route('/audit/{id}/crawl')]
class CrawlManageController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ExcludeCleaner $cleaner,
    ) {}

    #[Route('/manage', name: 'audit_crawl_manage', methods: ['GET'])]
    public function manage(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $pages = $site->getCrawlMetadata() ?? [];

        return $this->render('audit/crawl_manage.html.twig', [
            'site' => $site,
            'pages' => $pages,
            'usemenu' => true,
        ]);
    }

    #[Route('/manage/add', name: 'audit_crawl_manage_add', methods: ['POST'])]
    public function addPage(int $id, Request $request): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $url = trim((string) $request->request->get('url', ''));
        if ($url === '') {
            $this->addFlash('danger', 'URL requise.');
            return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
        }

        $pages = $site->getCrawlMetadata() ?? [];

        foreach ($pages as $page) {
            if ($page['url'] === $url) {
                $this->addFlash('warning', 'Cette URL existe déjà dans le crawl.');
                return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
            }
        }

        $pages[] = [
            'url' => $url,
            'status' => (int) $request->request->get('status', 200),
            'depth' => (int) $request->request->get('depth', 0),
            'title' => $request->request->get('title', '') ?: null,
            'metaDescription' => $request->request->get('metaDescription', '') ?: null,
            'h1Count' => (int) $request->request->get('h1Count', 0),
            'canonical' => $request->request->get('canonical', '') ?: null,
        ];

        $site->setCrawlMetadata($pages);
        $site->setCrawledPages(count($pages));
        $this->em->flush();

        $this->addFlash('success', 'Page ajoutée au crawl.');
        return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
    }

    #[Route('/manage/edit/{index}', name: 'audit_crawl_manage_edit', methods: ['POST'])]
    public function editPage(int $id, int $index, Request $request): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $pages = $site->getCrawlMetadata() ?? [];
        if (!isset($pages[$index])) {
            throw $this->createNotFoundException('Page non trouvée');
        }

        $oldUrl = $pages[$index]['url'];
        $newUrl = trim((string) $request->request->get('url', ''));

        $pages[$index] = [
            'url' => $newUrl,
            'status' => (int) $request->request->get('status', 200),
            'depth' => (int) $request->request->get('depth', 0),
            'title' => $request->request->get('title', '') ?: null,
            'metaDescription' => $request->request->get('metaDescription', '') ?: null,
            'h1Count' => (int) $request->request->get('h1Count', 0),
            'canonical' => $request->request->get('canonical', '') ?: null,
        ];

        $site->setCrawlMetadata($pages);
        $this->em->flush();

        if ($oldUrl !== $newUrl) {
            $this->syncPageReportsForUrl($site, $oldUrl, $newUrl);
        }

        $this->addFlash('success', 'Page mise à jour.');
        return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
    }

    #[Route('/manage/delete/{index}', name: 'audit_crawl_manage_delete', methods: ['POST'])]
    public function deletePage(int $id, int $index): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $pages = $site->getCrawlMetadata() ?? [];
        if (!isset($pages[$index])) {
            throw $this->createNotFoundException('Page non trouvée');
        }

        $removedUrl = $pages[$index]['url'];
        array_splice($pages, $index, 1);

        $site->setCrawlMetadata($pages);
        $site->setCrawledPages(count($pages));

        $this->removePageReportsForUrl($site, $removedUrl);
        $this->em->flush();

        $this->addFlash('success', 'Page supprimée du crawl et des analyses associées.');
        return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
    }

    #[Route('/manage/delete-excluded', name: 'audit_crawl_manage_delete_excluded', methods: ['POST'])]
    public function deleteExcluded(int $id): Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        $deleted = $this->cleaner->cleanSite($site);

        $this->addFlash('success', "{$deleted} page(s) exclue(s) supprimée(s) du crawl et des analyses.");
        return $this->redirectToRoute('audit_crawl_manage', ['id' => $id]);
    }

    private function removePageReportsForUrl(Site $site, string $url): void
    {
        $analyses = $this->em->getRepository(Analysis::class)
            ->findBy(['site' => $site]);

        foreach ($analyses as $analysis) {
            $reports = $this->em->getRepository(PageReport::class)
                ->findBy(['analysis' => $analysis, 'url' => $url]);

            foreach ($reports as $report) {
                $this->em->remove($report);
            }

            $count = $this->em->getRepository(PageReport::class)
                ->count(['analysis' => $analysis]);
            $analysis->setPagesCrawled($count);
            $analysis->setTotalPages($count);
        }
    }

    private function syncPageReportsForUrl(Site $site, string $oldUrl, string $newUrl): void
    {
        $analyses = $this->em->getRepository(Analysis::class)
            ->findBy(['site' => $site]);

        foreach ($analyses as $analysis) {
            $reports = $this->em->getRepository(PageReport::class)
                ->findBy(['analysis' => $analysis, 'url' => $oldUrl]);

            foreach ($reports as $report) {
                $report->setUrl($newUrl);
            }
        }
    }
}
