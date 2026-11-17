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

`make up` starts two containers: the app (FrankenPHP) and [Meilisearch](https://www.meilisearch.com), which holds the index of the ingredient reference. `make install` builds that index with Mistral embeddings (about 40 seconds, `MISTRAL_API_KEY` required).

The app is served on https://localhost.

## Reference data

`resources/data/ingredients.csv` is the ingredient reference: a French and English vocabulary (name and plural in each language, parent) and, for each of the 14 EU allergens, whether it is present in every ordinary version (`always`) or only in some products (`depends`).

`bin/console app:index` reads this file and builds the index through the Symfony AI Store component: one document per ingredient, embedded from its names, with its names and allergens stored in the document metadata, so that a search result is all the verdict needs.

The French vocabulary comes from [Marmiton](https://www.marmiton.org); the English names and the allergen columns were drafted with a language model and still need a human review.

## Matching

The names found in a recipe are matched to the reference by a Symfony AI agent (`ai.agent.matcher`). It calls the `search_ingredients` tool once with all the names, which embeds them in a single request and runs one hybrid search per name (a `HybridQuery`: half on the words of the name, half on its meaning) to return the five best ingredients of each, then picks one result for each name or none. The words find "olive oil" or "stock cube" where the embedding alone drifts to a neighbour, the meaning finds "muscade" behind "noix de muscade". Only ingredients the tool actually returned are accepted, and their allergens come from the index, never from the model (about 3 seconds for a recipe).

## Quality

```bash
make static   # PHP-CS-Fixer, Twig-CS-Fixer, PHPStan
make test     # PHPUnit
make ci       # both
```
