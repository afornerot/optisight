<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class JsonExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', static function ($value, bool $assoc = true) {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_array($value) || is_object($value)) {
                    return $value;
                }

                $decoded = json_decode((string) $value, $assoc);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }),
            new TwigFilter('json_pretty', static function ($value) {
                $data = $value;

                if (is_string($data)) {
                    $data = json_decode($data, true);
                }

                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }),
        ];
    }
}
