# TYPO3 AI Editorial Helper

Local-LLM-powered editorial assistant for TYPO3 v13. Generates SEO metadata,
tags, teasers, slugs, DE↔EN translation stubs, and content quality flags using
LM Studio on the local machine. **No data leaves the server.**

Built autonomously by [kairos](https://backant.io).

## Status

| Feature | Status |
|---|---|
| SEO meta description + page title (#1) | ✅ Shipped — first release |
| Auto-tag / category suggester (#2) | ⏳ Planned |
| Teaser / excerpt generator (#3) | ⏳ Planned |
| URL slug suggester (#4) | ⏳ Planned |
| DE↔EN translation stub (#5) | ⏳ Planned |
| Content quality flags (#6) | ⏳ Planned |

## Runtime requirements

- TYPO3 v13.0+
- PHP 8.2+
- [LM Studio](https://lmstudio.ai/) running locally with the local server enabled
- A loaded model — default: `qwen/qwen3-14b` (Qwen 3 14B Instruct).
  Other tested models: `mistralai/magistral-small-2509`.

LM Studio exposes an **OpenAI-compatible API** at `http://localhost:1234/v1`.
The extension uses LM Studio's `response_format: { type: "json_schema" }`
mode for structured output, which is grammar-constrained for guaranteed
parseable JSON.

## Installation

```bash
composer require kairos/typo3-ai-helper:dev-main
vendor/bin/typo3 extension:setup
```

## Configuration

In the TYPO3 install tool → **Settings → Extension Configuration → ai_editorial_helper**:

| Setting | Default | Description |
|---|---|---|
| `endpoint` | `http://localhost:1234/v1` | OpenAI-compatible base URL exposed by LM Studio |
| `model` | `qwen/qwen3-14b` | Model identifier as listed by `/v1/models` |
| `timeout` | `60` | Request timeout in seconds |
| `apiKey` | `` (empty) | Optional bearer token; LM Studio doesn't need one for local use |

## Usage — SEO meta description + page title

1. Edit a page in the TYPO3 backend.
2. Open the **SEO** tab.
3. Click **Generate with AI** next to the *Description* or *SEO Title* field.
4. The extension reads the page's title and tt_content (default language) and
   asks LM Studio for a meta description (≤160 chars) and SEO title (≤60 chars).
5. Both values are inserted into the form. Review, edit, save.

Errors (LM Studio not running, no model loaded) are surfaced as backend
notifications, not stack traces.

## Development

```bash
composer install
docker run --rm -v "$PWD":/app -w /app php:8.4-cli vendor/bin/phpunit -c Tests/UnitTests.xml
```

The unit tests cover the LM Studio HTTP client, the meta-description service
(prompt construction, JSON-schema response parsing, length enforcement,
mid-word cleanup, German content), and the extension settings DTO. They use
mocked HTTP, so they run without LM Studio.

A live integration check against a real LM Studio instance is documented in
the PR description for #1.

## Privacy

All inference happens on `localhost:1234`. The extension never makes outbound
network calls beyond the configured endpoint. No content, no metadata, no
analytics is sent to any third party.

## License

MIT — see `composer.json`.
