# Phase 1 プラン：データ収集モジュール構築・データ集積

## Context

ロードマップの Phase 1。Laravel 13 collector パッケージで、自治体・子育て支援情報を
自動収集するパイプラインを構築する。
Phase 2（ポータルサイト構築）に供給するJSONデータを生成することがゴール。

---

## 成果物一覧

| # | 成果物 | パス |
|---|--------|------|
| 1 | LLM統合サービス | `app/Services/LlmService.php` |
| 2 | クローラーサービス | `app/Services/CrawlerService.php` |
| 3 | データ抽出サービス | `app/Services/ExtractorService.php` |
| 4 | JSONストアサービス | `app/Services/DataStoreService.php` |
| 5 | クロールキュージョブ | `app/Jobs/CrawlPageJob.php` |
| 6 | Artisanコマンド | `app/Console/Commands/CollectRun.php` |
| 7 | デフォルト出力テンプレート | `resources/templates/childcare.json` |
| 8 | スケジューラー設定 | `routes/console.php` |
| 9 | PHPUnit テスト | `tests/Feature/` `tests/Unit/` |

---

## 実装ステップ

### Step 1: LLM統合基盤

**目的**: OpenRouter / Ollama への統一インターフェースを整備する。

**タスク:**
- `config/ai.php` を整備（プロバイダー・モデル・タイムアウト設定）
- `.env.example` に必要な変数を追記

```dotenv
DEFAULT_LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=anthropic/claude-3-haiku
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=llama3
```

- `App\Services\LlmService`：プロバイダー切り替え可能な chat() メソッド
  - Laravel HTTP Client でOpenRouter API を呼び出す
  - Ollamaは `/api/chat` エンドポイントを使用
  - タイムアウト・リトライ設定
- Unit テスト（HTTP fake でモック）

**完了条件:**
- `LlmService::chat($messages)` でOpenRouterにリクエストが飛ぶ
- プロバイダーを `.env` の `DEFAULT_LLM_PROVIDER` で切り替え可能

---

### Step 2: クローラーサービス

**目的**: エントリーポイントURLから再帰的にHTMLを取得する。

**タスク:**
- `App\Services\CrawlerService`
  - `fetch(string $url): string` — HTML取得（Laravel HTTP Client）
  - `extractLinks(string $html, string $baseUrl): array` — aタグからリンク抽出
  - 訪問済みURL管理（配列 or Cacheファサード）
  - 再帰深度上限（デフォルト: 2）
  - 同一ドメイン外リンクをスキップ
  - `robots.txt` の `Disallow` を尊重
  - レートリミット（リクエスト間に sleep）
- `App\Jobs\CrawlPageJob`：1ページのクロールをキュー化
- Feature テスト（HTTP fake）

**完了条件:**
- `CrawlerService::crawl($url, depth: 2)` で収集URLリストが返る
- 同一ドメイン外・robots.txt禁止パスはスキップされる

---

### Step 3: データ抽出サービス

**目的**: 取得したHTMLをLLMに渡し、テンプレート形式のJSONを抽出する。

**タスク:**
- `resources/templates/childcare.json` — デフォルト出力テンプレート

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
  "updated_at": ""
}
```

- `App\Services\ExtractorService`
  - `extract(string $html, string $url, array $template): array` — LLM抽出
  - システムプロンプト：テンプレートJSONのキーに沿って情報を抽出するよう指示
  - HTMLは本文のみに前処理（scriptタグ・styleタグ除去）してトークン削減
  - LLMレスポンスのJSONパース・バリデーション
  - 抽出失敗時は空配列を返す（例外を外に出さない）
- Unit テスト（LlmService をモック）

**完了条件:**
- `ExtractorService::extract($html, $url, $template)` でテンプレートに沿ったJSONが返る
- 抽出失敗時でもクラッシュしない

---

### Step 4: JSONストアサービス

**目的**: 抽出データをJSONファイルに永続化する。

**タスク:**
- `App\Services\DataStoreService`
  - 保存先: `storage/app/data/{source-domain}/{slug}.json`
  - `save(array $data): void` — JSONファイル書き込み
  - `findByUrl(string $url): ?array` — URL重複確認
  - `all(): Collection` — 全件取得（Phase 2 向け）
  - slug は URLから生成（urlslug or Str::slug）
  - upsert：同一URLのデータは上書き
- Unit テスト

**完了条件:**
- `storage/app/data/` 以下にJSONが保存される
- 同一URL再実行でファイルが上書きされる

---

### Step 5: Artisanコマンド

**目的**: 手動実行・CI実行のエントリーポイントを提供する。

**コマンド仕様:**
```
php artisan collect:run {url} {--depth=2} {--template=childcare} {--dry-run}
```

| オプション | 説明 |
|-----------|------|
| `url` | エントリーポイントURL（必須） |
| `--depth` | クロール深度（デフォルト: 2） |
| `--template` | 使用するテンプレート名（デフォルト: childcare） |
| `--dry-run` | 保存せずに結果を標準出力のみ |

**タスク:**
- `App\Console\Commands\CollectRun` 作成
- Laravel Pail 対応のログ出力（`Log::info()`）
- 各ページの抽出結果をプログレスバーで表示
- Feature テスト（コマンドの入出力検証）

**完了条件:**
- `php artisan collect:run https://example.com/kosodate --depth=2` が完走する
- `--dry-run` で保存なしに結果確認できる

---

### Step 6: スケジューラー設定

**目的**: 定期的な自動収集を設定する。

**タスク:**
- `config/collection_targets.php` — 収集対象URL・設定一覧

```php
return [
    [
        'url'      => 'https://example-city.lg.jp/kosodate/',
        'depth'    => 2,
        'template' => 'childcare',
        'schedule' => 'weekly',
    ],
    // ...
];
```

- `routes/console.php` にスケジュール定義
  - 週次で `collect:run` を対象URL分ループ実行
- キュー使用時は `QUEUE_CONNECTION=database` に変更

**完了条件:**
- `php artisan schedule:list` でジョブが表示される

---

### Step 7: データ集積（実証実行）

**目的**: 実際の自治体サイトでパイプラインを検証し、データを蓄積する。

**タスク:**
- 対象サイト選定（5〜10自治体）
- `collect:run` を実行・収集結果を手動レビュー
- 抽出精度が低い場合はプロンプトを調整
- Phase 2 で利用するJSONの最終スキーマを確定
- 収集件数：50件以上を目標

**完了条件:**
- 子育て支援情報 50件以上のJSONが `storage/app/data/` に保存済み
- 抽出精度を手動確認し合格（主要フィールドの充填率 80%以上）

---

## データフロー

```
収集対象URL
    │
    ▼
CrawlerService::crawl()
    │ ページURLリスト
    ▼
CrawlerService::fetch()  ×N
    │ HTML
    ▼
ExtractorService::extract()
    │ LLMService::chat()
    │ 抽出JSON
    ▼
DataStoreService::save()
    │
    ▼
storage/app/data/**/*.json  ← Phase 2 が読み込む
```

---

## 非機能要件

| 項目 | 要件 |
|------|------|
| レートリミット | リクエスト間隔 1秒以上 |
| タイムアウト | HTTP取得: 30秒、LLM: 60秒 |
| ログ | Laravel Pail / `storage/logs/laravel.log` |
| 機密情報 | APIキーは `.env` のみ、コミット禁止 |
| robots.txt | 必ず尊重する |
| トークン効率 | HTML前処理でscript/styleを除去してから送信 |

---

## 完了条件（Phase 1 全体）

- [ ] `php artisan collect:run <url>` でJSONが `storage/app/data/` に出力される
- [ ] LLM抽出精度を手動確認済み（主要フィールド充填率 80%以上）
- [ ] 子育て支援情報 50件以上収集済み
- [ ] `php artisan test --compact` がすべてパス
- [ ] `.env` / APIキーがコミットされていない
- [ ] `docs/phase1.md` をコミット済み

## 次フェーズへの引き継ぎ

Phase 2（ポータルサイト構築）で使用するJSONの最終スキーマを
`resources/templates/childcare.json` に確定させてからフェーズを終了する。
