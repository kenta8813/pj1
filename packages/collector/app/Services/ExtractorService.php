<?php

namespace App\Services;

use App\Ai\ChildcareExtractorAgent;
use App\Ai\GrantsExtractorAgent;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Symfony\Component\DomCrawler\Crawler;

class ExtractorService
{
    /** @var array<string, array<int, string>> テンプレート別の主要フィールド（充足率チェック対象） */
    private const MEANINGFUL_FIELDS = [
        'childcare' => ['target', 'summary', 'eligibility', 'application_method', 'contact'],
        'grants' => ['type', 'target', 'eligibility', 'application_method', 'contact'],
    ];

    private const MIN_FILLED_FIELDS = 2;

    public function __construct(
        private readonly ChildcareExtractorAgent $childcareAgent,
        private readonly GrantsExtractorAgent $grantsAgent,
    ) {}

    /**
     * HTMLからテンプレートJSONに沿って情報を抽出する。
     * テンプレート名に応じて使用するエージェントを切り替える。
     *
     * @param  array<string, mixed>  $template  出力テンプレート（キーのみ使用）
     * @param  string  $templateName  テンプレート名（例: childcare / grants）
     * @return array<string, mixed> 抽出結果。失敗・非関連ページは空配列
     */
    public function extract(string $html, string $url, array $template, string $templateName = 'childcare'): array
    {
        try {
            $cleaned = $this->truncate($this->cleanHtml($html));
            $prompt = $this->buildUserPrompt($cleaned, $url, $template);
            $agent = $this->resolveAgent($templateName);
            $response = (string) $agent->prompt($prompt, model: config('ai.model'));

            return $this->parseJsonResponse($response, $template, $templateName);
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

    private function resolveAgent(string $templateName): Agent
    {
        return match ($templateName) {
            'grants' => $this->grantsAgent,
            default => $this->childcareAgent,
        };
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
     * grants テンプレートは name フィールドが空なら非関連ページと判断して空配列を返す。
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $responseText, array $template, string $templateName = 'childcare'): array
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

        // 主キーが空なら非関連ページ
        $primaryKey = $templateName === 'grants' ? 'name' : 'title';
        if (($result[$primaryKey] ?? '') === '') {
            return [];
        }

        // フィールド充足率チェック: 主要フィールドのうち最低2個が非空でなければノイズ
        $meaningfulFields = self::MEANINGFUL_FIELDS[$templateName] ?? self::MEANINGFUL_FIELDS['childcare'];
        $filledCount = count(array_filter($meaningfulFields, fn ($f) => ($result[$f] ?? '') !== ''));
        if ($filledCount < self::MIN_FILLED_FIELDS) {
            return [];
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
