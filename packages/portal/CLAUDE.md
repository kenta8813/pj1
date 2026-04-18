@AGENTS.md

# portal — フロントエンド

## 概要
保護者向け子育て支援情報ポータル。SEO・AdSense最適化を最重要要件とするNext.jsアプリ。

## バージョン
- Next.js: 15.x (App Router)
- React: 19.x
- TypeScript + Tailwind CSS

## ホスティング
- Vercel（本番）
- `vercel.json` で `rootDirectory: packages/portal` を指定済み

## SEO・AdSense要件（最重要）
- ページは原則 SSG / ISR で静的生成
- `<head>` のメタタグ・OGP・構造化データを必ず設定
- AdSenseポリシー準拠：オリジナルコンテンツ、適切なカテゴリ

## ディレクトリ構造（主要）
```
portal/
├── src/
│   └── app/              # App Router ページ
├── public/               # 静的アセット
├── vercel.json           # Vercel設定
└── next.config.ts        # Next.js設定
```

## 注意
- `npm run build` が常に通る状態を維持する
- 環境変数は `.env.local`（コミット禁止）と `.env.example` で管理
