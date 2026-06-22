<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class LighthouseService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Run Lighthouse analysis on a URL.
     *
     * @return array{performance: float, accessibility: float, seo: float, bestPractices: float, raw: mixed}|null
     */
    public function analyze(string $url): ?array
    {
        $process = new Process([
            'lighthouse', $url,
            '--output=json',
            '--quiet',
            '--chrome-flags=--headless --no-sandbox --disable-gpu --disable-dev-shm-usage',
            '--only-categories=performance,accessibility,seo,best-practices',
        ]);
        $process->setTimeout(120);
        $process->setEnv(array_merge($_ENV, [
            'PATH' => $this->getBinPath(),
        ]));

        try {
            $process->run();

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
        }
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
