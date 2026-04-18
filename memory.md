# memory.md — 動的状態スナップショット

> このファイルはセッション終了前に更新する。静的な仕様は `CLAUDE.md` を参照。

## 現在のフェーズ

**Phase 0: ハーネス構築** — 完了 ✓

## 直近タスク

- [x] `docs/phase0.md` プラン策定・承認
- [x] `.claude/settings.json` 作成（hooks + permissions + mcpServers）
- [x] `CLAUDE.md` 作成
- [x] `memory.md` 作成
- [x] `.claude/agents/` 4ファイル作成
- [x] Next.js インストール（`packages/portal/`）
- [x] Laravel 13 インストール（`packages/collector/`）
- [x] `packages/*/CLAUDE.md` 作成
- [x] laravel-boost MCP インストール・設定
- [x] next-devtools-mcp 設定

## パッケージステータス

| パッケージ | ステータス |
|-----------|----------|
| `packages/portal` | 骨格完了（Next.js 15 + Tailwind） |
| `packages/collector` | 骨格完了（Laravel 13 + laravel/boost） |

## Decision Log

| 日付 | 決定事項 | 理由 |
|------|---------|------|
| 2026-04-18 | Vercelでポータルをホスト | 初期コスト最小・SSG/SSR対応 |
| 2026-04-18 | CLAUDE.md（静的）とmemory.md（動的）を分離 | 仕様書と進捗を混在させない |
| 2026-04-18 | curl/wgetはallow/denyなし（承認制） | 調査用途を残しつつ自動実行は防ぐ |
| 2026-04-18 | laravel-boost: php artisan boost:mcp（cwd: packages/collector） | boost:installが自動生成したコマンド |
| 2026-04-18 | next-devtools: npx -y next-devtools-mcp@latest | Vercel公式パッケージ、Node.js v20.19+必要 |

## 既知の課題・ブロッカー

- next-devtools-mcp は Next.js 16+ と実行中のdev serverが必要（一部ツール）
- Phase 1 開始前に要件定義（director エージェント）が必要
