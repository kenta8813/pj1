# memory.md — 動的状態スナップショット

> このファイルはセッション終了前に更新する。静的な仕様は `CLAUDE.md` を参照。

## 現在のフェーズ

**Phase 0: ハーネス構築** — 進行中

## 直近タスク

- [x] `docs/phase0.md` プラン策定・承認
- [x] `.claude/settings.json` 作成
- [x] `CLAUDE.md` 作成
- [x] `memory.md` 作成
- [ ] `.claude/agents/` 4ファイル作成
- [ ] Next.js インストール（`packages/portal/`）
- [ ] Laravel 13 インストール（`packages/collector/`）
- [ ] `packages/*/CLAUDE.md` 作成

## パッケージステータス

| パッケージ | ステータス |
|-----------|----------|
| `packages/portal` | 未着手 |
| `packages/collector` | 未着手 |

## Decision Log

| 日付 | 決定事項 | 理由 |
|------|---------|------|
| 2026-04-18 | Vercelでポータルをホスト | 初期コスト最小・SSG/SSR対応 |
| 2026-04-18 | CLAUDE.md（静的）とmemory.md（動的）を分離 | 仕様書と進捗を混在させない |
| 2026-04-18 | curl/wgetはallow/denyなし（承認制） | 調査用途を残しつつ自動実行は防ぐ |
| 2026-04-18 | laravel-boost / next-dev-tool MCP はTBD | 未インストール・要調査 |

## 既知の課題・ブロッカー

- laravel-boost MCP, next-dev-tool MCP のインストール方法が未確定
