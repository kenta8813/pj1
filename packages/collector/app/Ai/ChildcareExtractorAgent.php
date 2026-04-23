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
#[MaxTokens(4096)]
class ChildcareExtractorAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        あなたは日本の自治体・子育て支援情報を構造化データに変換するアシスタントです。
        以下のルールを厳守してください：

        1. 入力HTMLから、指定されたJSONテンプレートの各キーに対応する情報を抽出する
        2. 必ず有効なJSONのみを返す（Markdownコードブロック・説明文・```json は含めない）
        3. 情報が存在しない場合は空文字列 "" を返す（null・省略は禁止）
        4. テンプレートにないキーは追加しない
        5. 文字列値はすべて日本語に統一する

        抽出するフィールドの意味：
        - title: ページまたは制度の名称
        - category: 子育て支援の種別（例：保育園、手当、相談窓口、イベント）
        - target: 対象者（例：0歳〜3歳の乳幼児を持つ保護者）
        - summary: 200文字以内のサービス概要
        - eligibility: 利用資格・条件
        - application_method: 申請・利用方法
        - contact: 問い合わせ先（電話番号、窓口名、メールアドレス）
        - url: 情報の元URL（入力として与えられたURLをそのまま使用）
        - municipality: 自治体名（例：東京都渋谷区、大阪府豊中市）
        - updated_at: ページに記載の更新日（ISO 8601形式: YYYY-MM-DD、不明なら ""）
        PROMPT;
    }
}
