# Sans

Paste the URL of a recipe: Sans extracts it, shows which of the 14 EU regulatory allergens it contains, and can propose an adapted version that avoids the allergens you switched off.

Built for the Symfony AI & MCP Hackathon. Every allergen verdict comes from a deterministic lookup in PHP; the language model only extracts and normalises ingredients and proposes substitutions.

## Requirements

- Docker with Compose
- A Mistral API key

## Setup

```bash
make build up
make install
```

Put your keys in `.env.local`:

```dotenv
MISTRAL_API_KEY=...
```

The app is served on https://localhost.

## Quality

```bash
make static   # PHP-CS-Fixer, Twig-CS-Fixer, PHPStan
make test     # PHPUnit
make ci       # both
```
