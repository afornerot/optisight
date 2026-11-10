<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

#[\Symfony\Component\Routing\Attribute\Route('/audit/stream')]
class StreamCommandController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private KernelInterface $kernel,
    ) {}

    #[\Symfony\Component\Routing\Attribute\Route('/crawl/{id}', name: 'audit_stream_crawl', methods: ['GET'])]
    public function streamCrawl(int $id, Request $request): StreamedResponse
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            return new Response('Site not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        return $this->streamCommand('app:crawl', ['site_id' => $id], 'crawl', $id, $request->query->has('force'));
    }

    #[\Symfony\Component\Routing\Attribute\Route('/rgaa/{id}', name: 'audit_stream_rgaa', methods: ['GET'])]
    public function streamRgaa(int $id, Request $request): StreamedResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new Response('Analysis not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        return $this->streamCommand('app:rgaa', ['analysis_id' => $id], 'analysis', $id, $request->query->has('force'));
    }

    #[\Symfony\Component\Routing\Attribute\Route('/ai/{id}', name: 'audit_stream_ai', methods: ['GET'])]
    public function streamAi(int $id, Request $request): StreamedResponse
    {
        $analysis = $this->em->getRepository(Analysis::class)->find($id);
        if (!$analysis) {
            return new Response('Analysis not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        return $this->streamCommand('app:ai', ['analysis_id' => $id], 'analysis', $id, $request->query->has('force'));
    }

    #[\Symfony\Component\Routing\Attribute\Route('/stop/{type}/{id}', name: 'audit_stream_stop', methods: ['POST'])]
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

    private function streamCommand(string $command, array $arguments, string $type, int $id, bool $force = false): StreamedResponse
    {
        $consolePath = $this->kernel->getProjectDir() . '/bin/console';
        $pidFile = $this->getPidFile($type, $id);
        $logFile = $this->getLogFile($type, $id);

        $response = new StreamedResponse(function () use ($consolePath, $command, $arguments, $pidFile, $logFile, $force) {
            // Flux long : aucune limite de temps (voir history, php.ini = 30 s).
            set_time_limit(0);
            // Le client peut partir sans tuer l analyse : on le detecte et on
            // rend la main, le process detache continue son travail.
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ini_set('output_buffering', '0');
            ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);

            // L analyse est lancee DETACHEE (nohup) : elle survit a la coupure
            // de la connexion HTTP, a un reload de la page, ou a la fermeture
            // de l onglet. Un coupure ne laisse donc plus le statut bloque sur
            // "running" avec un process mort.
            // "Relancer" (force) : on tue l eventuel process en cours et on
            // repart d un journal vide, pour vraiment tout refaire. Une
            // reconnexion automatique, elle, ne force pas et reprend.
            if ($force) {
                if ($this->isProcessAlive($pidFile)) {
                    $pid = (int) trim((string) file_get_contents($pidFile));
                    $this->killProcessTree($pid);
                    usleep(300000);
                }
                @unlink($logFile);
                @unlink($pidFile);
            }

            if (!$this->isProcessAlive($pidFile)) {
                @unlink($logFile);
                $joined = implode(' ', array_map('escapeshellarg', array_merge(
                    [$consolePath, $command],
                    array_map('strval', $arguments)
                )));
                exec(sprintf(
                    'nohup php %s >> %s 2>&1 < /dev/null & echo $! > %s',
                    $joined,
                    escapeshellarg($logFile),
                    escapeshellarg($pidFile)
                ));
                usleep(250000);
            }

            // Lecture depuis le debut du fichier : un reload retrouve tout ce
            // qui a deja ete produit, puis suit en temps reel.
            $fh = @fopen($logFile, 'rb');
            $lastData = time();
            while (true) {
                $chunk = $fh ? fread($fh, 8192) : '';
                if (is_string($chunk) && '' !== $chunk) {
                    echo $chunk;
                    $lastData = time();
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    continue;
                }

                if (connection_aborted()) {
                    // Client parti : le process detache continue seul.
                    break;
                }

                if (!$this->isProcessAlive($pidFile)) {
                    $tail = $fh ? stream_get_contents($fh) : '';
                    if (is_string($tail) && '' !== $tail) {
                        echo $tail;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                    break;
                }

                // Battement de coeur : un appel LLM peut rester silencieux
                // bien plus de 30 s, et Chrome coupe une requete sans aucune
                // donnee apres 30 s ("Error in input stream"). Une ligne vide
                // est ignoree par le lecteur (renderBuffer saute les lignes
                // blanches) mais reset le compteur d inactivite du navigateur.
                if (time() - $lastData >= 10) {
                    echo "\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastData = time();
                }

                usleep(100000);
            }

            if ($fh) {
                fclose($fh);
            }

            if (!$this->isProcessAlive($pidFile)) {
                @unlink($pidFile);
            }
        });

        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }

    private function getLogFile(string $type, int $id): string
    {
        return sys_get_temp_dir() . "/optisight_{$type}_{$id}.log";
    }

    /**
     * Le processus de l analyse est detache (nohup) : il doit survivre a la
     * coupure de la connexion HTTP, sinon chaque coupure tue l analyse et
     * laisse le statut bloque sur "running".
     */
    private function isProcessAlive(string $pidFile): bool
    {
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 1) {
            return false;
        }

        exec("kill -0 {$pid} 2>/dev/null", $output, $code);

        return 0 === $code;
    }


    private function getPidFile(string $type, int $id): string
    {
        return sys_get_temp_dir() . "/optisight_{$type}_{$id}.pid";
    }
}
