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
class GrantsExtractorAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        あなたは日本の自治体が提供する給付金・助成金・手当の情報を構造化データに変換するアシスタントです。
        以下のルールを厳守してください：

        1. 入力HTMLから、指定されたJSONテンプレートの各キーに対応する情報を抽出する
        2. 必ず有効なJSONのみを返す（Markdownコードブロック・説明文・```json は含めない）
        3. 情報が存在しない場合は空文字列 "" を返す（null・省略は禁止）
        4. テンプレートにないキーは追加しない
        5. 文字列値はすべて日本語に統一する

        抽出するフィールドの意味：
        - name: 給付金・助成金・手当の正式名称（例：児童手当、乳幼児医療費助成）
        - type: 種別（"現金給付" / "現物給付" / "医療費助成" / "保育料助成" / "その他助成" のいずれか）
        - amount: 支給額・助成額（例：月額1万5千円、上限3万円/年、所得に応じて変動）
        - amount_note: 金額に関する補足（所得制限・年齢による変動・上限条件など）
        - target: 対象者（例：中学校卒業まで、第3子以降、ひとり親家庭）
        - eligibility: 受給・利用資格・条件（住所要件、所得要件など）
        - period: 支給・助成の期間（例：出生から中学校卒業まで、年度内）
        - deadline: 申請期限・受付期間（例：出生後60日以内、随時受付）
        - application_method: 申請方法（窓口・オンライン・郵送など）
        - required_documents: 必要書類（例：健康保険証、印鑑、マイナンバーカード）
        - contact: 問い合わせ先（電話番号・窓口名・メールアドレス）
        - url: 情報の元URL（入力として与えられたURLをそのまま使用）
        - municipality: 自治体名（例：富山県高岡市）
        - updated_at: ページに記載の更新日（ISO 8601形式: YYYY-MM-DD、不明なら ""）

        給付金・助成金でないページ（一般的な案内・施設情報など）の場合は
        name フィールドを空文字列 "" にしてください。
        PROMPT;
    }
}
