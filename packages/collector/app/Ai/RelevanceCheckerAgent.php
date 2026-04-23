<?php

namespace App\Ai;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenRouter)]
#[Temperature(0.0)]
#[MaxTokens(32)]
class RelevanceCheckerAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        あなたは自治体ページの関連性を判定するアシスタントです。
        入力されたJSONデータが「具体的な制度・サービス・給付金のページ」かを判定し、
        {"relevant": true} または {"relevant": false} のみをJSONで返してください。

        falseとすべきケース:
        - よくある質問・Q&Aページ
        - 組織・課の業務案内
        - 手続き一覧・申請ガイド（特定制度でなく複数手続きをまとめたもの）
        - カテゴリ・インデックスページ（下位ページへのリンク集）
        - 施設一覧・遊び場紹介
        PROMPT;
    }
}
