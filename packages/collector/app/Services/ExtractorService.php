<?php

namespace App\Services;

use App\Ai\ChildcareExtractorAgent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ExtractorService
{
    public function __construct(
        private readonly ChildcareExtractorAgent $agent,
    ) {}

    /**
     * HTMLからテンプレートJSONに沿って子育て支援情報を抽出する。
     *
     * @param  array<string, mixed>  $template  出力テンプレート（キーのみ使用）
     * @return array<string, mixed> 抽出結果。失敗・非関連ページは空配列
     */
    public function extract(string $html, string $url, array $template): array
    {
        try {
            $cleaned = $this->truncate($this->cleanHtml($html));
            $prompt = $this->buildUserPrompt($cleaned, $url, $template);
            $response = (string) $this->agent->prompt($prompt, model: config('ai.model'));

            return $this->parseJsonResponse($response, $template);
        } catch (\Throwable $e) {
            Log::warning("ExtractorService: 抽出失敗 [{$url}] — {$e->getMessage()}");

            return [];
        }
    }

    /**
     * HTMLからscript/style/nav/footer等を除去してテキストを抽出する。
     */
    public function cleanHtml(string $html): string
    {
        $crawler = new Crawler($html);

        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'header', 'aside'] as $tag) {
            $crawler->filter($tag)->each(function (Crawler $node): void {
                $domNode = $node->getNode(0);

                if ($domNode && $domNode->parentNode) {
                    $domNode->parentNode->removeChild($domNode);
                }
            });
        }

        $text = strip_tags($crawler->count() > 0 ? $crawler->html() : $html);

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private function truncate(string $text): string
    {
        $maxChars = config('ai.crawler.max_html_chars', 12000);

        return mb_substr($text, 0, $maxChars);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function buildUserPrompt(string $cleanText, string $url, array $template): string
    {
        $templateJson = json_encode($template, JSON_UNESCAPED_UNICODE);

        return <<<TEXT
        以下のページコンテンツから情報を抽出し、JSONテンプレートの形式で返してください。

        ページURL: {$url}

        JSONテンプレート:
        {$templateJson}

        ページコンテンツ:
        {$cleanText}
        TEXT;
    }

    /**
     * LLMレスポンスをJSONパースしてテンプレートキーに絞り込む。
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $responseText, array $template): array
    {
        // LLMが誤って ```json ... ``` を付けた場合の保護
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $responseText);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $responseText);

        $trimmed = trim($cleaned ?? $responseText);
        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            // 途中切れのJSONを修復: 最後の完全なプロパティまでを取り出して閉じる
            $decoded = $this->repairPartialJson($trimmed);
        }

        if (! is_array($decoded)) {
            Log::warning('ExtractorService: JSONパース失敗', ['response' => mb_substr($responseText, 0, 200)]);

            return [];
        }

        // テンプレートキーのみに絞り込み、欠損キーは "" で補完
        $result = [];

        foreach (array_keys($template) as $key) {
            $value = $decoded[$key] ?? '';
            $result[$key] = is_array($value) ? $value : (string) $value;
        }

        return $result;
    }

    /**
     * 途中で切れたJSONを修復してパースを試みる。
     *
     * @return array<string, mixed>|null
     */
    private function repairPartialJson(string $text): ?array
    {
        if (! str_starts_with($text, '{')) {
            return null;
        }

        // 末尾をカンマや未閉じ文字列で終わっている場合、閉じ括弧を補う
        $candidate = rtrim($text, ", \t\n\r").'}';
        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // 最後の未閉じ文字列値を取り除いてから閉じる
        $cut = preg_replace('/,?\s*"[^"]*"\s*:\s*"[^"]*$/su', '', $text);

        if ($cut !== null && $cut !== $text) {
            $decoded = json_decode(rtrim($cut, ', ').' }', true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
