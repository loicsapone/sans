# How Sans works

Details of each stage of the pipeline. The overview is in the [README](../README.md).

## Reference data

`resources/data/ingredients.csv` is the ingredient reference: a French and English vocabulary (name and plural in each language, parent) and, for each of the 14 EU allergens, whether it is present in every ordinary version (`always`) or only in some products (`depends`).

The index is built from this file (`make index` with Docker) with the commands of the Symfony AI Store component (`ai:store:setup`, `ai:store:clear`, then `ai:store:index ingredients`): its `CsvLoader` reads the rows, and a transformer turns each of them into one document per ingredient, embedded from its names, with its names and allergens stored in the document metadata, so that a search result is all the verdict needs.

The French vocabulary comes from [Marmiton](https://www.marmiton.org); the English names and the allergen columns were drafted with a language model and still need a human review. The rows from id 90001 are the free-from substitutes that swaps rely on (rice flour, soy milk, coconut oil...): they were written by hand, with their allergens, from the swaps the agent proposes most.

## Matching

The names found in a recipe are matched to the reference by a Symfony AI agent (`ai.agent.matcher`). It calls the `search_ingredients` tool once with all the names, which embeds them in a single request and runs one hybrid search per name (a `HybridQuery`: half on the words of the name, half on its meaning) to return the five best ingredients of each, then picks one result for each name or none. The words find "olive oil" or "stock cube" where the embedding alone drifts to a neighbour, the meaning finds "muscade" behind "noix de muscade". Only ingredients the tool actually returned are accepted, and their allergens come from the index, never from the model (about 3 seconds for a recipe).

## Analysing a recipe

```bash
bin/console app:analyze https://example.com/a-recipe
```

The command fetches the page (public web pages only, with a size and time limit), reads the recipe from its schema.org data or, failing that, with the model (which is only sent the readable part of the page, found by [php-readability](https://github.com/j0k3r/php-readability)), normalises the ingredient lines, matches them to the reference and prints the allergens it found with the ingredients responsible. It reports the stage that failed (`fetch`, `extract`, `normalise`, `match`) and the time each stage took.

On the page, an ingredient the reference does not know asks the visitor which allergens it contains, or none. The answer goes through the same verdict engine, stays in the page and is never stored with the analysis everybody shares.

## Cooking without an allergen

Tapping the mark of an allergen asks an agent (`ai.agent.adapter`) for a swap for each ingredient line that carries it, plus the steps rewritten to match. Each swap comes with the role the ingredient plays in the recipe (binder, fat, leavening...), an enum of the structured output. The agent has a `check_ingredients` tool to look up its own proposals before answering; that tool runs the matcher, so one agent calls another. Whatever the agent did with it, the model proposes and the server checks: the plain ingredients of each swap are looked up with the matcher, and a swap is kept only if all of them are in the reference and none carries (or may carry) an avoided allergen. Refused swaps are asked again with the reason, three attempts at most; a line without a safe swap says so. Ingredients that only *may* carry the allergen (a stock cube, a chocolate) are offered to the agent too, once: when it has no ingredient that is surely free of it, the page asks to read the label. Adaptations are stored by address and set of allergens. A swap takes 10 to 15 seconds, and how many lines succeed depends on how well the reference covers common substitutes.

## Reading the labels

For an ingredient that only *may* carry an avoided allergen, Sans is a client of the [Open Food Facts MCP server](https://github.com/cyanheads/openfoodfacts-mcp-server) to say what the labels of real products say: "On Open Food Facts, 469 of 992 products in “Stock cubes” declare celery."

An agent (`ai.agent.labels`) gets the tools of the remote server (`mcp_server: food.openfoodfacts` in `config/packages/ai.yaml`, the connection is in `config/packages/mcp.yaml`) and finds the category of the ingredient in the Open Food Facts taxonomy. The counts are then asked to the server from PHP, through the same MCP connection: the model picks a category, it never writes a number. The sentence is a Live component of its own (`ProductLabel`) that the recipe page loads after itself (`loading="defer"`): the lookup starts when the allergen is tapped and runs next to the swaps, without holding them back. What was found is cached for a week.

It is a help to choose a product, not a verdict: Open Food Facts is filled in by volunteers, many products have no allergen entered, and the category the agent picks can change from one run to the next. The server is a public one (`OPENFOODFACTS_MCP_URL`) with a small budget of searches; when it does not answer, the page only asks to read the label. Finding the categories takes about 10 seconds, sometimes 20; the adapted recipe does not wait for it.
