# TYPO3 AI Editorial Helper

Local-LLM-powered editorial assistant for TYPO3 v13. Generates SEO metadata,
tags, teasers, slugs, DE↔EN translation stubs, and content quality flags using
Ollama on the local machine. No data leaves the server.

Built autonomously by [kairos](https://backant.io).

## Features (planned)

- SEO meta description + page title generator
- Auto-tag / category suggester
- Teaser / excerpt generator
- URL slug suggester
- DE↔EN translation stub
- Content quality flags (sentence length, missing headings, tone)

## Runtime

- Ollama running locally (`ollama serve`)
- Default model: `qwen2.5:7b-instruct` (fallback: `llama3.1:8b-instruct-q4_K_M`)
- Endpoint: `http://localhost:11434` (configurable per extension setting)
