# TYPO3 AI Editorial Helper

Local-LLM-powered editorial assistant for TYPO3 v13. Generates SEO metadata,
categories, teasers, slugs, DE↔EN translation stubs, and content quality flags
using LM Studio on the local machine. **No data leaves the server.**

Built autonomously by [kairos](https://backant.io).

## Status

All v1 features shipped:

| Feature | Status |
|---|---|
| SEO meta description + page title (#1) | ✅ Shipped |
| Auto-tag / category suggester (#2) | ✅ Shipped |
| Teaser / excerpt generator (#3) | ✅ Shipped |
| URL slug suggester (#4) | ✅ Shipped |
| DE↔EN translation stub (#5) | ✅ Shipped |
| Content quality flags (#6) | ✅ Shipped |

## Runtime requirements

- TYPO3 v13.0+
- **PHP 8.4+** (TYPO3 v13.4 transitively requires PHP 8.4 via `typo3/cms-core`)
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

## Where the wizards appear

| Wizard | Form field | Page record |
|---|---|---|
| Generate meta description + SEO title | `description`, `seo_title` | `pages` (SEO tab) |
| Suggest categories | `categories` | `pages` |
| Generate teaser | `abstract` | `pages` (SEO tab) |
| Suggest slug | `slug` | `pages` (works on unsaved pages too) |
| Generate translation stub | `bodytext` | `tt_content` (only on localised rows) |
| Check page quality | `title` | `pages` |

Errors (LM Studio not running, no model loaded, GET timeouts) are surfaced
as backend notifications with the LM Studio's own error message — never as
stack traces or `[object Object]`.

## Development

```bash
composer install
vendor/bin/phpunit -c Tests/UnitTests.xml
```

If you don't have PHP 8.4 locally:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c \
  'composer install --no-progress --no-interaction --prefer-dist \
   && vendor/bin/phpunit -c Tests/UnitTests.xml'
```

CI runs `lint` + `unit-tests` jobs on every push and pull request — see
`.github/workflows/ci.yml`. 117 unit tests cover the LM Studio HTTP client,
the six editorial services (with prompt + schema assertions, length / format
enforcement, German-content handling, exception propagation), and the
FormEngine wizard registration regression for issue #11.

## Privacy

All inference happens on the configured `endpoint` (default
`http://localhost:1234`). The extension never makes outbound network calls
beyond that endpoint. No content, no metadata, no analytics is sent to any
third party.

## License

MIT — see `composer.json`.
