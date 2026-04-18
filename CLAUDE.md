# プロジェクト: LLMデータキュレーション型ポータルサイト

## 概要
LLMを活用したデータキュレーション型ポータルサイトの汎用基盤。
初回ユースケース：日本の自治体・子育て支援情報の保護者向けポータル。

## 技術スタック（モノレポ構成）

| レイヤー | 技術 |
|---------|-----|
| データ収集 | Laravel 13 + AI SDK |
| データ保管 | JSONストア or SQLite（初期） |
| フロントエンド | Next.js + React + Tailwind CSS |
| LLMプロバイダー | OpenRouter（メイン）+ Ollama（ローカル） |
| ホスティング | Vercel（portal）|

## モノレポ構造

```
pj1/
├── CLAUDE.md                 # 本ファイル（静的・仕様書）
├── memory.md                 # 動的：進捗・決定事項（セッション末に更新）
├── .claude/
│   ├── settings.json         # Claude Code設定（hooks/permissions/mcp）
│   └── agents/               # サブエージェント定義
├── packages/
│   ├── collector/            # Laravel 13 データ収集モジュール
│   └── portal/               # Next.js フロントエンド
└── docs/                     # ドキュメント・フェーズプラン
```

> 現在の進捗・決定事項は `memory.md` を参照。

## エージェント役割分担

| エージェント | 定義ファイル | 役割 |
|-----------|------------|------|
| パーソナルエージェント | `.claude/agents/personal.md` | メインセッション・ユーザー対話・他エージェントへの振り分け |
| ディレクター | `.claude/agents/director.md` | 企画・デザイン・仕様・要件・ユーザーストーリー |
| リードエンジニア | `.claude/agents/lead-engineer.md` | 技術選定・実装プランニング |
| エンジニア | `.claude/agents/engineer.md` | 実装・テスト |

## 開発規約

### ブランチ戦略
- `main`: リリースブランチ
- `develop`: 統合ブランチ
- `feature/<name>`: 機能開発
- `claude/<task>`: Claudeによる自動タスク

### コミットメッセージ
```
<type>: <summary>

type: feat / fix / refactor / docs / chore / test
```

### 非機能要件（最重要）
- SEO最適化（SSR/SSG徹底）
- AdSense対応（コンテンツポリシー遵守）
- 機密情報の流出防止（.env, キー類は絶対にコミットしない）
