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
            $response = (string) $this->agent->prompt($prompt);

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

        $decoded = json_decode(trim($cleaned ?? $responseText), true);

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
}
