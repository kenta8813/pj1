# collector — データ収集モジュール

## 概要
エントリーポイントURLからリンクを再帰的に辿り、LLMでデータ抽出してJSONを出力するLaravelアプリ。

## バージョン
- Laravel: 13.x (composer: `laravel/laravel ^13.0`)
- PHP: 8.2+

## 入出力仕様
- **入力**: エントリーポイントURL、収集指示プロンプト、出力テンプレート（JSON）
- **動作**: 再帰的リンク追跡 → LLMでデータ抽出
- **出力**: テンプレート形式のJSON

## LLMプロバイダー設定
- 設定ファイル: `config/ai.php`
- 環境変数: `.env` の `OPENROUTER_API_KEY`, `OLLAMA_BASE_URL` 等
- デフォルト: OpenRouter（`DEFAULT_LLM_PROVIDER=openrouter`）

## ディレクトリ構造（主要）
```
collector/
├── app/
│   ├── Services/         # ビジネスロジック（クローラー、LLM呼び出し）
│   └── Jobs/             # キュージョブ
├── config/
│   └── ai.php            # LLMプロバイダー設定
└── .env.example          # 環境変数テンプレート（APIキーを含まない）
```

## 注意
- `.env` はコミット禁止。`.env.example` のみ管理する
- APIキー・シークレットは絶対にコードに埋め込まない
