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

The app is served on https://localhost. Paste the link of a recipe, or open `/?url=<recipe link>` to share an analysis: an address that was already analysed is shown at once (analyses are kept for a week).

## Reference data

`resources/data/ingredients.csv` is the ingredient reference: a French and English vocabulary (name and plural in each language, parent) and, for each of the 14 EU allergens, whether it is present in every ordinary version (`always`) or only in some products (`depends`).

`bin/console app:index` reads this file and builds the index through the Symfony AI Store component: one document per ingredient, embedded from its names, with its names and allergens stored in the document metadata, so that a search result is all the verdict needs.

The French vocabulary comes from [Marmiton](https://www.marmiton.org); the English names and the allergen columns were drafted with a language model and still need a human review. The rows from id 90001 are the free-from substitutes that swaps rely on (rice flour, soy milk, coconut oil...): they were written by hand, with their allergens, from the swaps the agent proposes most.

## Matching

The names found in a recipe are matched to the reference by a Symfony AI agent (`ai.agent.matcher`). It calls the `search_ingredients` tool once with all the names, which embeds them in a single request and runs one hybrid search per name (a `HybridQuery`: half on the words of the name, half on its meaning) to return the five best ingredients of each, then picks one result for each name or none. The words find "olive oil" or "stock cube" where the embedding alone drifts to a neighbour, the meaning finds "muscade" behind "noix de muscade". Only ingredients the tool actually returned are accepted, and their allergens come from the index, never from the model (about 3 seconds for a recipe).

## Analysing a recipe

```bash
bin/console app:analyze https://example.com/a-recipe
```

The command fetches the page (public web pages only, with a size and time limit), reads the recipe from its schema.org data or, failing that, with the model, normalises the ingredient lines, matches them to the reference and prints the allergens it found with the ingredients responsible. It reports the stage that failed (`fetch`, `extract`, `normalise`, `match`) and the time each stage took.

## Cooking without an allergen

Tapping the mark of an allergen asks an agent (`ai.agent.adapter`) for a swap for each ingredient line that always carries it, plus the steps rewritten to match. The agent has a `check_ingredients` tool to look up its own proposals before answering; that tool runs the matcher, so one agent calls another. Whatever the agent did with it, the model proposes and the server checks: the plain ingredients of each swap are looked up with the matcher, and a swap is kept only if all of them are in the reference and none carries (or may carry) an avoided allergen. Refused swaps are asked again with the reason, three attempts at most; a line without a safe swap says so. Ingredients that only *may* carry the allergen are not swapped, the page asks to read the label. Adaptations are stored by address and set of allergens. A swap takes 10 to 15 seconds, and how many lines succeed depends on how well the reference covers common substitutes.

## Quality

```bash
make static   # PHP-CS-Fixer, Twig-CS-Fixer, PHPStan
make test     # PHPUnit
make ci       # both
```
