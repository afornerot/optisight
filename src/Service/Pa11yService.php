<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class Pa11yService
{
    private LoggerInterface $logger;
    private string $standard;
    private int $timeout;
    private int $wait;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->standard = $_ENV['PA11Y_STANDARD'] ?? 'WCAG2AA';
        $this->timeout = (int) ($_ENV['PA11Y_TIMEOUT'] ?? 30000);
        $this->wait = (int) ($_ENV['PA11Y_WAIT'] ?? 1000);
    }

    /**
     * Run pa11y accessibility analysis on a URL.
     *
     * @return array{score: float, errors: array, warnings: array, raw: mixed}|null
     */
    public function analyze(string $url): ?array
    {
        $configFile = tempnam(sys_get_temp_dir(), 'pa11y_') . '.json';
        file_put_contents($configFile, json_encode([
            'chromeLaunchConfig' => [
                'executablePath' => '/usr/bin/chromium-browser',
                'args' => [
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-gpu',
                ],
            ],
        ]));

        try {
            $process = new Process([
                'pa11y',
                '--standard', $this->standard,
                '--reporter', 'json',
                '--timeout', (string) $this->timeout,
                '--wait', (string) $this->wait,
                '--config', $configFile,
                $url,
            ]);
            $process->setTimeout(120);
            $process->setEnv(array_merge($_ENV, [
                'PATH' => $this->getBinPath(),
                'CHROME_BIN' => '/usr/bin/chromium-browser',
                'PUPPETEER_CACHE_DIR' => '/tmp/puppeteer_cache',
            ]));

            $process->run();
            $output = $process->getOutput() ?: $process->getErrorOutput();

            if (empty($output)) {
                return null;
            }

            $issues = json_decode($output, true);
            if (!is_array($issues)) {
                return null;
            }

            $errors = array_values(array_filter($issues, fn($i) => ($i['type'] ?? '') === 'error'));
            $warnings = array_values(array_filter($issues, fn($i) => ($i['type'] ?? '') === 'warning'));

            $score = 100.0 - (count($errors) * 5.0) - (count($warnings) * 1.0);
            $score = max(0, $score);

            return [
                'score' => round($score, 1),
                'errors' => $errors,
                'warnings' => $warnings,
                'raw' => $issues,
            ];
        } catch (\Exception $e) {
            $this->logger->error('pa11y exception for: ' . $url, ['error' => $e->getMessage()]);
            return null;
        } finally {
            @unlink($configFile);
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
