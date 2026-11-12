<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class LighthouseService
{
    private LoggerInterface $logger;

    /** @var array<int, string> Fichiers d'en-tetes temporaires, par processus */
    private array $headerFiles = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Lance Lighthouse sans attendre sa fin : l'appelant démarre ensuite
     * l'autre moteur, et les deux navigateurs tournent en parallèle.
     *
     * @return Process|null null si le processus n'a pas pu démarrer
     */
    public function start(string $url, ?string $cookieHeader = null): ?Process
    {
        $args = [
            'lighthouse', $url,
            '--output=json',
            '--quiet',
            '--chrome-flags=--headless --no-sandbox --disable-gpu --disable-dev-shm-usage --disable-software-rasterizer',
            '--only-categories=performance,accessibility,seo,best-practices',
        ];

        $extraHeadersFile = null;
        if ($cookieHeader !== null) {
            $extraHeadersFile = tempnam(sys_get_temp_dir(), 'lh_headers_') . '.json';
            file_put_contents($extraHeadersFile, json_encode([
                'Cookie' => $cookieHeader,
            ]));
            $args[] = '--extra-headers=' . $extraHeadersFile;
        }

        $process = new Process($args);
        $process->setTimeout(120);
        $process->setEnv(array_merge($_ENV, [
            'PATH' => $this->getBinPath(),
            'CHROME_BIN' => '/usr/bin/chromium',
            // HOME par defaut (/var/www) n'est pas inscriptible par apache :
            // chromium ne peut pas creer son profil et l'analyse s'arrete
            // au premier lancement ("Failed to create headless user data
            // directory container"). /tmp est inscriptible et deja utilise
            // par Pa11yService (PUPPETEER_CACHE_DIR).
            'HOME' => '/tmp',
        ]));

        if ($extraHeadersFile !== null) {
            $this->headerFiles[spl_object_id($process)] = $extraHeadersFile;
        }

        try {
            // Pas de pkill ici : les deux moteurs lancent leur Chromium en
            // meme temps, tuer tous les processus chromium tuerait l'autre.
            $process->start();
        } catch (\Exception $e) {
            $this->logger->error('Lighthouse could not start for: ' . $url, ['error' => $e->getMessage()]);
            $this->cleanup($process);
            return null;
        }

        return $process;
    }

    /**
     * Attend la fin d'un processus lance par start() et lit son rapport.
     *
     * @return array{performance: float, accessibility: float, seo: float, bestPractices: float, raw: mixed}|null
     */
    public function parse(?Process $process, string $url): ?array
    {
        if ($process === null) {
            return null;
        }

        try {
            $process->wait();

            if (!$process->isSuccessful()) {
                $this->logger->warning('Lighthouse failed for: ' . $url, [
                    'error' => $process->getErrorOutput(),
                ]);
                return null;
            }

            $data = json_decode($process->getOutput(), true);
            if (!is_array($data) || !isset($data['categories'])) {
                return null;
            }

            return [
                'performance' => round(($data['categories']['performance']['score'] ?? 0) * 100, 1),
                'accessibility' => round(($data['categories']['accessibility']['score'] ?? 0) * 100, 1),
                'seo' => round(($data['categories']['seo']['score'] ?? 0) * 100, 1),
                'bestPractices' => round(($data['categories']['best-practices']['score'] ?? 0) * 100, 1),
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Lighthouse exception for: ' . $url, ['error' => $e->getMessage()]);
            return null;
        } finally {
            $this->cleanup($process);
        }
    }

    /**
     * Lance Lighthouse et attend son rapport — raccourci pour un seul moteur.
     *
     * @return array{performance: float, accessibility: float, seo: float, bestPractices: float, raw: mixed}|null
     */
    public function analyze(string $url, ?string $cookieHeader = null): ?array
    {
        return $this->parse($this->start($url, $cookieHeader), $url);
    }

    private function cleanup(Process $process): void
    {
        $id = spl_object_id($process);
        if (!isset($this->headerFiles[$id])) {
            return;
        }

        if (file_exists($this->headerFiles[$id])) {
            @unlink($this->headerFiles[$id]);
        }
        unset($this->headerFiles[$id]);
    }

    private function getBinPath(): string
    {
        $paths = [
            '/usr/local/bin',
            '/usr/bin',
            '/usr/local/sbin',
            '/usr/sbin',
            '/sbin',
            getenv('PATH') ?: '',
        ];
        return implode(':', array_filter($paths));
    }
}
