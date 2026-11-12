<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Mesure, dans la page rendue, les faits d'un nœud : couleurs calculées,
 * opacité cumulée, ratio de contraste, taille de cible, nom accessible.
 *
 * Enveloppe bin/inspect.js (Puppeteer) — là où Pa11y nomme la règle, ce script
 * donne la cause mesurée.
 */
class InspectService
{
    private LoggerInterface $logger;
    private string $projectDir;

    public function __construct(LoggerInterface $logger, string $projectDir)
    {
        $this->logger = $logger;
        $this->projectDir = $projectDir;
    }

    /**
     * Inspect one node of a rendered page.
     *
     * @return array<string, mixed>|null The script payload, or null if it could
     *                                    not run or did not return valid JSON.
     */
    public function inspect(
        string $url,
        string $selector,
        ?string $cookieHeader = null,
        int $waitMs = 1500,
        string $viewport = '1280x900'
    ): ?array {
        $command = [
            'node',
            $this->projectDir . '/bin/inspect.js',
            '--url', $url,
            '--selector', $selector,
            '--wait', (string) $waitMs,
            '--viewport', $viewport,
        ];

        if ($cookieHeader !== null && $cookieHeader !== '') {
            $command[] = '--cookie';
            $command[] = $cookieHeader;
        }

        $process = new Process($command);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, [
            'NODE_PATH' => '/usr/local/lib/node_modules',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => '/tmp',
            'CHROME_BINARY' => '/usr/bin/chromium',
        ]));
        $process->run();

        // Le script écrit son JSON sur stdout ; en cas d'échec (exception,
        // argument manquant) il écrit sur stderr et sort en code 1.
        $output = $process->getOutput() ?: $process->getErrorOutput();

        if ($output === '') {
            $this->logger->error('Inspect: empty output', [
                'url' => $url,
                'selector' => $selector,
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            return null;
        }

        $result = json_decode(trim($output), true);

        if (!is_array($result)) {
            $this->logger->error('Inspect: invalid JSON output', [
                'url' => $url,
                'selector' => $selector,
                'exit_code' => $process->getExitCode(),
                'output' => $output,
                'error' => $process->getErrorOutput(),
            ]);

            return null;
        }

        if (isset($result['error'])) {
            $this->logger->error('Inspect: script error', [
                'url' => $url,
                'selector' => $selector,
                'error' => $result['error'],
            ]);
            $result['error'] = (string) $result['error'];
        }

        return $result;
    }
}
