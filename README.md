# TYPO3 AI Editorial Helper

Local-LLM-powered editorial assistant for TYPO3 v13. Generates SEO metadata,
tags, teasers, slugs, DE↔EN translation stubs, and content quality flags using
LM Studio on the local machine. No data leaves the server.

Built autonomously by [kairos](https://backant.io).

## Features (planned)

- SEO meta description + page title generator
- Auto-tag / category suggester
- Teaser / excerpt generator
- URL slug suggester
- DE↔EN translation stub
- Content quality flags (sentence length, missing headings, tone)

## Runtime

- [LM Studio](https://lmstudio.ai/) running locally with the local server enabled
- Default model: `qwen/qwen3-14b` (Qwen 3 14B Instruct via LM Studio)
- Endpoint: `http://localhost:1234/v1` (OpenAI-compatible API, configurable per extension setting)
- Load the model into LM Studio's UI before using the extension; the extension surfaces a clear backend message if no model is loaded.
