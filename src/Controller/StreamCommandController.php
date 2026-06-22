<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/audit/stream')]
class StreamCommandController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private KernelInterface $kernel,
    ) {}

    #[Route('/crawl/{id}', name: 'audit_stream_crawl', methods: ['GET'])]
    public function streamCrawl(int $id): StreamedResponse
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            return new Response('Site not found', 404);
        }

        return $this->streamCommand('app:crawl', ['site_id' => $id], 'crawl', $id);
    }

    #[Route('/rgaa/{id}', name: 'audit_stream_rgaa', methods: ['GET'])]
    public function streamRgaa(int $id): StreamedResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new Response('Analysis not found', 404);
        }

        return $this->streamCommand('app:rgaa', ['analysis_id' => $id], 'analysis', $id);
    }

    #[Route('/ai/{id}', name: 'audit_stream_ai', methods: ['GET'])]
    public function streamAi(int $id): StreamedResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new Response('Analysis not found', 404);
        }

        return $this->streamCommand('app:ai', ['analysis_id' => $id], 'analysis', $id);
    }

    #[Route('/stop/{type}/{id}', name: 'audit_stream_stop', methods: ['POST'])]
    public function stop(string $type, int $id): JsonResponse
    {
        $pidFile = $this->getPidFile($type, $id);

        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0) {
                $this->killProcessTree($pid);
            }
            @unlink($pidFile);
        }

        if ($type === 'analysis') {
            $analysis = $this->em->getRepository(Analysis::class)->find($id);
            if ($analysis && $analysis->getStatus() === Analysis::STATUS_RUNNING) {
                $analysis->setStatus(Analysis::STATUS_CANCELLED);
                $this->em->flush();
            }
        } elseif ($type === 'crawl') {
            $site = $this->em->getRepository(Site::class)->find($id);
            if ($site && $site->getCrawlStatus() === Site::CRAWL_STATUS_RUNNING) {
                $site->setCrawlStatus(Site::CRAWL_STATUS_CANCELLED);
                $this->em->flush();
            }
        }

        return new JsonResponse(['ok' => true]);
    }

    private function killProcessTree(int $pid): void
    {
        exec("pkill -TERM -P {$pid} 2>/dev/null");
        usleep(100000);
        exec("kill -TERM {$pid} 2>/dev/null");
        usleep(500000);
        exec("pkill -9 -P {$pid} 2>/dev/null");
        exec("kill -9 {$pid} 2>/dev/null");
    }

    private function streamCommand(string $command, array $arguments, string $type, int $id): StreamedResponse
    {
        $consolePath = $this->kernel->getProjectDir() . '/bin/console';
        $pidFile = $this->getPidFile($type, $id);

        $response = new StreamedResponse(function () use ($consolePath, $command, $arguments, $pidFile) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ini_set('output_buffering', '0');
            ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);

            $process = new Process(array_merge(
                ['php', $consolePath, $command],
                array_map('strval', $arguments)
            ));
            $process->setTimeout(null);
            $process->start();

            file_put_contents($pidFile, $process->getPid());

            while ($process->isRunning()) {
                usleep(10000);
                echo $process->getIncrementalOutput();
                echo $process->getIncrementalErrorOutput();
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            @unlink($pidFile);

            echo $process->getIncrementalOutput();
            echo $process->getIncrementalErrorOutput();
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        });

        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }

    private function getPidFile(string $type, int $id): string
    {
        return sys_get_temp_dir() . "/iargaaseo_{$type}_{$id}.pid";
    }
}
