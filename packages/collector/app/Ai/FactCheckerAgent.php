<?php

namespace App\Ai;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenRouter)]
#[Model('anthropic/claude-3-haiku')]
#[Temperature(0.0)]
#[MaxTokens(1024)]
class FactCheckerAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        あなたは日本の自治体・子育て支援情報のファクトチェッカーです。
        与えられた「抽出済みJSON」と「現在のページHTML」を比較し、情報の正確性を検証してください。

        返答はJSONのみで返してください（説明・コードブロック不要）。形式:
        {
          "confidence": "high|medium|low",
          "issues": ["フィールド名: 問題の説明", ...]
        }

        confidence の基準:
        - high: 主要フィールドがHTML内容と一致している
        - medium: 一部フィールドが確認できないか微妙に異なる可能性がある
        - low: 大幅に内容が異なるか、ページが存在しない・大きく変更されている

        issues: 問題があるフィールドのみ記載する（問題なければ空配列 []）
        PROMPT;
    }
}
