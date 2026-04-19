<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DataStoreService
{
    private const DISK = 'data';

    public function save(array $data): void
    {
        $path = $this->buildPath($data['url'] ?? '');
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        Storage::disk(self::DISK)->put($path, $encoded);
    }

    public function findByUrl(string $url): ?array
    {
        $path = $this->buildPath($url);

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $content = Storage::disk(self::DISK)->get($path);
        $decoded = json_decode($content ?? '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        $files = Storage::disk(self::DISK)->allFiles();

        return collect($files)
            ->filter(fn (string $f) => str_ends_with($f, '.json'))
            ->map(function (string $f): ?array {
                $content = Storage::disk(self::DISK)->get($f);
                $decoded = json_decode($content ?? '', true);

                return is_array($decoded) ? $decoded : null;
            })
            ->filter()
            ->values();
    }

    public function countByDomain(string $domain): int
    {
        $host = parse_url($domain, PHP_URL_HOST) ?? $domain;
        $slug = str_replace('.', '-', $host);

        return count(Storage::disk(self::DISK)->allFiles($slug));
    }

    public function buildPath(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'unknown';
        $path = $parsed['path'] ?? '/';

        $hostSlug = str_replace('.', '-', $host);
        $pathSlug = trim(str_replace('/', '-', $path), '-');

        if ($pathSlug === '' || $pathSlug === '-') {
            $pathSlug = 'index';
        }

        return "{$hostSlug}/{$pathSlug}.json";
    }
}
