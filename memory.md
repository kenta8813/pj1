# memory.md — 動的状態スナップショット

> このファイルはセッション終了前に更新する。静的な仕様は `CLAUDE.md` を参照。

## 現在のフェーズ

**Phase 1: データ収集モジュール** — 実装完了 ✓（テスト全通過）
**高岡市データ収集** — 実行完了（22件保存）✓

## 直近タスク

### Phase 0（完了）
- [x] `docs/phase0.md` プラン策定・承認
- [x] `.claude/settings.json` 作成（hooks + permissions + mcpServers）
- [x] `CLAUDE.md` / `memory.md` 作成
- [x] `.claude/agents/` 4ファイル作成
- [x] Next.js インストール（`packages/portal/`）
- [x] Laravel 13 インストール（`packages/collector/`）
- [x] laravel-boost / next-devtools-mcp 設定

### Phase 1（完了）
- [x] `docs/phase1.md` 更新（自律探索・ファクトチェック・3エージェント設計）
- [x] `laravel/ai` SDK + `symfony/dom-crawler` 導入
- [x] `config/ai.php` → Laravel AI SDK 形式に書き換え（OpenRouter/Ollama）
- [x] `config/filesystems.php` → `data` ディスク追加
- [x] `config/collection_targets.php` 新規作成
- [x] `resources/templates/childcare.json` 新規作成（`_fc_*` フィールド含む）
- [x] `app/Ai/ChildcareExtractorAgent.php` 実装
- [x] `app/Ai/LinkFilterAgent.php` 実装
- [x] `app/Ai/FactCheckerAgent.php` 実装
- [x] `app/Services/FetchService.php` 実装（HTTP取得・リンク抽出・robots.txt）
- [x] `app/Services/ExtractorService.php` 実装（HTML→JSON抽出）
- [x] `app/Services/DataStoreService.php` 実装（`data` ディスクへJSONファイル保存）
- [x] `app/Services/SiteExplorerService.php` 実装（自律探索の司令塔）
- [x] `app/Services/FactCheckService.php` 実装（ファクトチェック実行）
- [x] `app/Jobs/ExploreJob.php` 実装（探索のキュー化）
- [x] `app/Console/Commands/CollectRun.php` 実装（`collect:run`）
- [x] `app/Console/Commands/FactCheckVerify.php` 実装（`collect:verify`）
- [x] `routes/console.php` → スケジューラー追記（週次収集・月次ファクトチェック）
- [x] 単体テスト 35件 / フィーチャーテスト 29件（計64件、全パス）
- [x] LLMモデルを `LLM_MODEL` 環境変数で設定可能に（デフォルト: `google/gemini-2.0-flash-exp:free`）
- [x] `.env.example` 作成

### 高岡市データ収集（完了）
- [x] `www.city.takaoka.toyama.jp` に対して `collect:run` 実行
- [x] `ExtractorService::repairPartialJson()` 追加（途中切れJSON対応）
- [x] `ChildcareExtractorAgent` MaxTokens 2048→4096 に増加
- [x] `config/collection_targets.php` に高岡市エントリ追加
- [x] 22件の子育て支援情報を `storage/app/data/www-city-takaoka-toyama-jp/` に保存

### 給付・助成金特化収集（完了）
- [x] `resources/templates/grants.json` 新規作成（name/type/amount/deadline 等）
- [x] `app/Ai/GrantsExtractorAgent.php` 実装（給付特化プロンプト）
- [x] `ExtractorService` をテンプレート名でエージェント切り替え対応に改修
- [x] `SiteExplorerService` に `templateName` パラメータ追加
- [x] `LinkFilterAgent` の instructions に給付系カテゴリを追記
- [x] テスト全通過（66件）
- [x] 高岡市給付ページ 10件追加保存（計32件）

## パッケージステータス

| パッケージ | ステータス |
|-----------|----------|
| `packages/portal` | 骨格完了（Next.js 15 + Tailwind） |
| `packages/collector` | Phase 1 実装完了・テスト全通過 |

## Decision Log

| 日付 | 決定事項 | 理由 |
|------|---------|------|
| 2026-04-18 | Vercelでポータルをホスト | 初期コスト最小・SSG/SSR対応 |
| 2026-04-18 | CLAUDE.md（静的）とmemory.md（動的）を分離 | 仕様書と進捗を混在させない |
| 2026-04-18 | curl/wgetはallow/denyなし（承認制） | 調査用途を残しつつ自動実行は防ぐ |
| 2026-04-18 | laravel-boost: php artisan boost:mcp（cwd: packages/collector） | boost:installが自動生成したコマンド |
| 2026-04-18 | next-devtools: npx -y next-devtools-mcp@latest | Vercel公式パッケージ、Node.js v20.19+必要 |
| 2026-04-19 | LLM連携に `laravel/ai` SDK使用 | Laravel 13公式・属性ベースで宣言的 |
| 2026-04-19 | LLMプロバイダーはOpenRouter（デフォルト）+ Ollama（ローカル） | コスト管理・ローカル開発両立 |
| 2026-04-19 | 自律探索: LinkFilterAgentがリンク選別 | 盲目的全リンク走査を避けLLMコスト抑制 |
| 2026-04-19 | データ保存先は `storage/app/data/` 専用ディスク | `.gitignore` 対象にして収集データをコミットしない |
| 2026-04-19 | ファクトチェック結果は `_fc_*` フィールドとして元JSONに追記 | 別ファイル管理より一元管理が扱いやすい |
| 2026-04-19 | LLMモデルを `LLM_MODEL` env変数で切り替え可能に | PHP属性はコンパイル時定数のためenv不可→prompt()の`model:`引数で解決 |
| 2026-04-19 | デフォルトモデルを `google/gemini-2.0-flash-exp:free` に設定 | 開発・テスト時のAPIコスト無料化 |
| 2026-04-19 | 高岡市収集エントリポイントを `/gyosei/kosodate_kyoiku/index.html` に設定 | トップページはJS依存でリンク6件のみ、子育てセクション直指定で効率化 |
| 2026-04-19 | ChildcareExtractorAgent MaxTokens 2048→4096 | free model がJSON途中で切れる問題を回避 |

## 既知の課題・ブロッカー

- `ExampleTest`（`tests/Feature/ExampleTest.php`）は `APP_KEY` 未設定で失敗（Phase 1 実装とは無関係、既存の未解決問題）
- next-devtools-mcp は Next.js 16+ と実行中のdev serverが必要（一部ツール）
- Phase 2 開始前にポータルUI設計（director エージェント）が必要
