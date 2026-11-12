<?php

namespace App\Mcp\Resources;

use App\Entity\McpApiKey;

class GuideMcpResource
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function get(array $arguments, ?McpApiKey $apiKey): array
    {
        $topic = $arguments['topic'] ?? null;
        $path = $this->projectDir . '/MCP.md';

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Le guide MCP.md est illisible : {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Le guide MCP.md est illisible : {$path}");
        }

        if ($topic === null || $topic === '') {
            return ['guide' => $content];
        }

        $filtered = $this->filterSections($content, (string) $topic);

        return [
            'guide' => $filtered['guide'],
            'topic' => $topic,
            'topics' => $filtered['topics'],
        ];
    }

    /**
     * Ne garde que les sections dont le titre (niveau « ## » ou « ### »)
     * contient le mot demandé, sans tenir compte de la casse. Une section de
     * niveau 2 emporte ses sous-sections.
     *
     * @return array{guide: string, topics: string[]}
     */
    private function filterSections(string $content, string $topic): array
    {
        $lines = explode("\n", $content);
        $headings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^(#{2,3})\s+(.*\S)\s*$/', $line, $matches) === 1) {
                $headings[] = [
                    'index' => $index,
                    'level' => strlen($matches[1]),
                    'title' => trim($matches[2]),
                ];
            }
        }

        $needle = mb_strtolower($topic);
        $topics = [];
        $sections = [];
        $coveredUntil = 0;
        $headingCount = count($headings);

        foreach ($headings as $position => $heading) {
            if ($heading['index'] < $coveredUntil) {
                continue;
            }

            if (mb_strpos(mb_strtolower($heading['title']), $needle) === false) {
                continue;
            }

            $end = count($lines);
            for ($next = $position + 1; $next < $headingCount; $next++) {
                if ($headings[$next]['level'] <= $heading['level']) {
                    $end = $headings[$next]['index'];
                    break;
                }
            }

            $topics[] = $heading['title'];
            $sections[] = implode("\n", array_slice($lines, $heading['index'], $end - $heading['index']));
            $coveredUntil = $end;
        }

        return [
            'guide' => implode("\n", $sections),
            'topics' => $topics,
        ];
    }
}
