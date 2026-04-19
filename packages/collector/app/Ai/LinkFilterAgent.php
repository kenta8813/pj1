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
#[MaxTokens(512)]
class LinkFilterAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        あなたは日本の自治体ウェブサイトのナビゲーターです。
        与えられたURLリストから、子育て・育児支援に関連しそうなURLのみを返してください。

        関連カテゴリ（広めに判断すること）：
        保育園・幼稚園・こども園・認定こども園、手当・補助金・給付金・助成、
        医療費助成・乳幼児医療、母子手帳・妊婦健診・産前産後ケア、
        育児相談・子育て支援センター、学童保育・放課後・ファミリーサポート、
        障害児支援・発達相談、入学・就学援助・教育支援

        返答形式：関連URLのみをJSON配列で返す（説明・コードブロック不要）
        例: ["https://...", "https://..."]
        関連URLがなければ空配列 [] を返す。
        PROMPT;
    }
}
