# Phase 1 プラン：データ収集モジュール構築・データ集積

## Context

ロードマップの Phase 1。Laravel 13 collector パッケージで、自治体・子育て支援情報を
自動収集するパイプラインを構築する。
Phase 2（ポータルサイト構築）に供給するJSONデータを生成することがゴール。

LLM 連携には Laravel 13 公式 AI SDK（`laravel/ai`）を使用する。

---

## アーキテクチャ概要

### 自律探索フロー

```
入力: 自治体トップURL
         │
         ▼
    FetchService::fetch()        → HTML取得
         │
         ▼
    FetchService::extractLinks() → 同一ドメイン全リンク
         │
         ▼
    LinkFilterAgent              ← LLMが子育て関連リンクを選別
         │ 関連URLリスト
         ▼
    キューに追加（深度カウント付き）
         │
         ▼（各ページで繰り返し）
    ExtractorService::extract()  ← LLMがデータ抽出
         │ 子育て支援データ
         ▼
    DataStoreService::save()     → storage/app/data/**/*.json
```

### ファクトチェックフロー（独立コマンド）

```
DataStoreService::all()          → 収集済みJSONを全件取得
         │
         ▼
    FetchService::fetch(url)      → 元ページを再取得
         │
         ▼
    FactCheckerAgent              ← 抽出データ vs 現在のHTMLを比較
         │ {confidence, issues}
         ▼
    DataStoreService::save()      → _fc_* フィールドを追記して上書き
```

---

## 成果物一覧

| # | 成果物 | パス |
|---|--------|------|
| 1 | データ抽出エージェント | `app/Ai/ChildcareExtractorAgent.php` |
| 2 | リンク選別エージェント | `app/Ai/LinkFilterAgent.php` |
| 3 | ファクトチェックエージェント | `app/Ai/FactCheckerAgent.php` |
| 4 | HTTP取得・リンク抽出 | `app/Services/FetchService.php` |
| 5 | HTML→JSON抽出 | `app/Services/ExtractorService.php` |
| 6 | JSONストア | `app/Services/DataStoreService.php` |
| 7 | 自律探索司令塔 | `app/Services/SiteExplorerService.php` |
| 8 | ファクトチェック実行 | `app/Services/FactCheckService.php` |
| 9 | 探索キュージョブ | `app/Jobs/ExploreJob.php` |
| 10 | 収集コマンド | `app/Console/Commands/CollectRun.php` |
| 11 | ファクトチェックコマンド | `app/Console/Commands/FactCheckVerify.php` |
| 12 | 出力テンプレート | `resources/templates/childcare.json` |
| 13 | 収集対象設定 | `config/collection_targets.php` |
| 14 | PHPUnit テスト | `tests/Unit/` `tests/Feature/` |

---

## JSONスキーマ（`resources/templates/childcare.json`）

```json
{
  "title": "",
  "category": "",
  "target": "",
  "summary": "",
  "eligibility": "",
  "application_method": "",
  "contact": "",
  "url": "",
  "municipality": "",
  "updated_at": "",

  "_fc_checked_at": "",
  "_fc_confidence": "",
  "_fc_issues": []
}
```

| フィールド | 説明 |
|-----------|------|
| `title` | ページ/制度の名称 |
| `category` | 子育て支援の種別（保育園・手当・相談窓口・イベント等） |
| `target` | 対象者 |
| `summary` | 200文字以内の概要 |
| `eligibility` | 利用資格・条件 |
| `application_method` | 申請・利用方法 |
| `contact` | 問い合わせ先（電話・窓口・メール） |
| `url` | 情報の元URL |
| `municipality` | 自治体名（例: 東京都渋谷区） |
| `updated_at` | ページ記載の更新日（YYYY-MM-DD） |
| `_fc_checked_at` | ファクトチェック実行日時（ISO 8601） |
| `_fc_confidence` | `"high"` / `"medium"` / `"low"` |
| `_fc_issues` | 問題フィールドと理由の配列 |

---

## エージェント設計（Laravel AI SDK）

### `ChildcareExtractorAgent`
- Provider: `Lab::OpenRouter` / Model: `anthropic/claude-3-haiku`
- 役割: ページHTMLからJSONテンプレートに沿って子育て支援情報を抽出
- Temperature: 0.0 / MaxTokens: 2048

### `LinkFilterAgent`
- Provider: `Lab::OpenRouter` / Model: `anthropic/claude-3-haiku`
- 役割: URLリストから子育て支援に関連するリンクのみを選別
- Temperature: 0.0 / MaxTokens: 512

### `FactCheckerAgent`
- Provider: `Lab::OpenRouter` / Model: `anthropic/claude-3-haiku`
- 役割: 抽出済みデータと現在のHTMLを比較して信頼性スコアを付与
- Temperature: 0.0 / MaxTokens: 1024
- 返答形式: `{"confidence": "high|medium|low", "issues": [...]}`

---

## コマンド仕様

### 収集コマンド

```bash
php artisan collect:run {url}
    {--depth=3}       # 探索深度
    {--pages=100}     # 最大ページ数（暴走防止）
    {--template=childcare}
    {--dry-run}       # 保存せずログ出力のみ
    {--queue}         # ExploreJob に投入して非同期実行
```

### ファクトチェックコマンド

```bash
php artisan collect:verify
    {--url=}               # 特定URLのみ
    {--confidence=all}     # all|high|medium|low|unchecked
    {--dry-run}
    {--queue}
```

---

## スケジューラー

```php
// 週次収集（collection_targets.php で管理）
Schedule::call(...)->weekly()->name('collect:weekly')->withoutOverlapping();

// 月次ファクトチェック（medium/low を再検証）
Schedule::command('collect:verify --confidence=medium')
    ->monthly()->name('factcheck:monthly')->withoutOverlapping();
```

---

## 非機能要件

| 項目 | 要件 |
|------|------|
| レートリミット | リクエスト間隔 1秒以上（`CRAWLER_RATE_LIMIT_MS`） |
| タイムアウト | HTTP: 30秒、LLM: SDK デフォルト |
| 文字コード | Shift-JIS / EUC-JP は UTF-8 に変換 |
| robots.txt | `CRAWLER_RESPECT_ROBOTS=true` で尊重 |
| トークン効率 | script/style/nav/footer 除去後に最大12,000文字でトリミング |
| 機密情報 | APIキーは `.env` のみ。`storage/app/data/` はコミットしない |
| 暴走防止 | `--depth` + `--pages` の二重制限 |

---

## LLMコスト試算（参考）

| 処理 | 概算 |
|------|------|
| `LinkFilterAgent` × 100ページ | 約 $0.02 |
| `ChildcareExtractorAgent` × 30件 | 約 $0.03 |
| `FactCheckerAgent` × 30件 | 約 $0.03 |
| **1自治体あたり合計** | **約 $0.08** |

（OpenRouter 経由、`anthropic/claude-3-haiku` 使用時）

---

## 完了条件

- [ ] `php artisan collect:run <自治体URL>` でJSONが `storage/app/data/` に出力される
- [ ] `LinkFilterAgent` による自律探索で子育て支援ページを網羅的に発見できる
- [ ] `php artisan collect:verify` でファクトチェック結果が `_fc_*` フィールドに書き込まれる
- [ ] 子育て支援情報 50件以上収集済み
- [ ] `php artisan test --compact` がすべてパス
- [ ] `.env` / APIキーがコミットされていない

## 次フェーズへの引き継ぎ

Phase 2（ポータルサイト構築）で使用するJSONの最終スキーマは
`resources/templates/childcare.json` で確定済み。
`_fc_confidence` フィールドを使ってポータル側で信頼性フィルタリングが可能。
