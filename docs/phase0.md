# Phase 0 プラン：ハーネス構築

## Context
readme.mdのロードマップに基づき、Phase 1以降の開発を効率的に進めるための
開発環境・エージェント基盤を先行実装する。
目的：Claude Codeの設定最適化、MCP連携、サブエージェント体制、
モノレポ骨格の確立。

---

## 成果物一覧

| # | 成果物 | パス |
|---|--------|------|
| 1 | Claude Code設定 + Hooks | `.claude/settings.json` |
| 2 | 静的プロジェクトコンテキスト | `CLAUDE.md` |
| 3 | 動的状態管理 | `memory.md` |
| 4 | エージェント定義 | `.claude/agents/` |
| 5 | モノレポ骨格 + フレームワーク導入 | `packages/` |
| 6 | MCP設定 | `.claude/settings.json` の `mcpServers` |
| 7 | パッケージ別コンテキスト | `packages/*/CLAUDE.md` |

---

## 実装ステップ

### Step 1: `.claude/settings.json` 作成
承認要求を最小化し、機密情報のみブロックする設定。

```json
{
  "$schema": "https://json.schemastore.org/claude-code-settings.json",
  "hooks": {
    "Stop": [
      {
        "matcher": "",
        "hooks": [
          {
            "type": "command",
            "command": "echo '\\n[Hook] セッション終了前に memory.md の更新を確認してください'"
          }
        ]
      }
    ]
  },
  "permissions": {
    "allow": [
      "Bash(git *)", "Bash(npm *)", "Bash(npx *)", "Bash(pnpm *)",
      "Bash(composer *)", "Bash(php artisan *)",
      "Bash(ls *)", "Bash(mkdir *)", "Bash(cp *)", "Bash(mv *)",
      "Read(*)", "Write(*)", "Edit(*)", "Glob(*)", "Grep(*)", "Skill"
    ],
    "deny": [
      "Bash(curl * | bash)", "Bash(curl * | sh)",
      "Bash(wget * | bash)", "Bash(wget * | sh)",
      "Bash(curl *-d*.env*)", "Bash(curl *--data*.env*)",
      "Bash(nc *)", "Bash(ncat *)", "Bash(* > /dev/tcp/*)",
      "Bash(ssh * -R *)", "Bash(scp *)",
      "Read(**/.env)", "Read(**/.env.*)",
      "Read(**/id_rsa)", "Read(**/id_ed25519)",
      "Read(**/*.pem)", "Read(**/*.key)",
      "Read(**/credentials)", "Read(**/.aws/*)", "Read(**/.ssh/*)"
    ]
  },
  "mcpServers": {
    "laravel-boost": { "command": "TODO", "args": [] },
    "next-dev-tool":  { "command": "TODO", "args": [] }
  }
}
```

**セキュリティ設計方針:**
- `curl`/`wget` は allow/deny なし → ユーザー承認制（調査用途で都度確認）
- deny でパイプ実行・リバースシェル・機密ファイル読み取りをブロック
- WebFetch/WebSearch ツール（Claude Code組み込み）を優先
- 既存の `/root/.claude/settings.json`（グローバル）と競合しないよう注意

> **MCP 注意**: `laravel-boost` / `next-dev-tool` はシステム未インストール。
> 判明次第 `mcpServers` を更新する。

---

### Step 2: `CLAUDE.md` + `memory.md` 作成

| ファイル | 種別 | 用途 |
|---------|------|------|
| `CLAUDE.md` | 静的 | 仕様・規約（Claude Code自動検出） |
| `memory.md` | 動的 | 進捗・決定事項（セッション末に手動更新） |
| `packages/collector/CLAUDE.md` | 静的 | Laravel固有コンテキスト |
| `packages/portal/CLAUDE.md` | 静的 | Next.js固有コンテキスト |

Stop Hook でセッション終了時に `memory.md` 更新を促す。

---

### Step 3: サブエージェント定義 (`.claude/agents/`)

| ファイル | エージェント | 役割 |
|---------|------------|------|
| `personal.md` | パーソナルエージェント | メインセッション・振り分け |
| `director.md` | ディレクター | 企画・仕様・ユーザーストーリー |
| `lead-engineer.md` | リードエンジニア | 技術選定・実装プランニング |
| `engineer.md` | エンジニア | 実装・テスト |

---

### Step 4: フレームワーク導入

#### Next.js (portal)
```bash
cd packages/portal
npx create-next-app@latest . --typescript --tailwind --eslint --app --src-dir --import-alias "@/*"
```
- Vercelホスティング前提 → `vercel.json` に `rootDirectory` 指定

#### Laravel 13 (collector)
```bash
cd packages/collector
composer create-project laravel/laravel . "^13.0"
```
- `.env.example` にOpenRouter API key 変数を追記
- `config/ai.php` でLLMプロバイダー設定の骨格を作成

---

## 最終ディレクトリ構造

```
pj1/
├── CLAUDE.md                 # 静的：プロジェクト全体コンテキスト
├── memory.md                 # 動的：進捗・決定事項
├── README.md
├── .claude/
│   ├── settings.json
│   └── agents/
│       ├── personal.md
│       ├── director.md
│       ├── lead-engineer.md
│       └── engineer.md
├── packages/
│   ├── collector/            # Laravel 13
│   │   ├── CLAUDE.md
│   │   └── config/ai.php
│   └── portal/               # Next.js + Tailwind
│       ├── CLAUDE.md
│       └── vercel.json
└── docs/
    ├── phase0.md             # 本ファイル
    └── architecture.md       # （Phase 1以降に追記）
```

---

## 検証方法

1. `claude --print-config` でプロジェクト設定が読み込まれることを確認
2. `claude mcp list` でMCPサーバー一覧を確認（TBD）
3. `cd packages/portal && npm run build` でNext.jsビルドが通ることを確認
4. `cd packages/collector && php artisan serve` でLaravel起動確認

---

## 完了条件

- [x] `.claude/settings.json` 作成
- [x] `CLAUDE.md` 作成（静的コンテキスト）
- [x] `memory.md` 作成（動的状態管理）
- [x] `.claude/agents/` 4ファイル作成
- [x] Next.js インストール（`packages/portal/`）+ `vercel.json`
- [x] Laravel 13 インストール（`packages/collector/`）
- [x] `packages/*/CLAUDE.md` 作成
- [x] `docs/phase0.md` 作成（本ファイル）
- [ ] 全ファイルをコミット・プッシュ

## ブロッカー / 未解決
- laravel-boost MCP, next-dev-tool MCP のインストール方法が未確定
