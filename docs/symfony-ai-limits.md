# Limits met with the Symfony AI components

Notes taken while building Sans on `symfony/ai` 1.0.0-BETA1. Each entry says what got in the way, what Sans does about it, and what a fix could look like. None of them is a blocker.

## Store: Meilisearch bridge

### The Flex recipe writes an option the bundle refuses

`composer require symfony/ai-meilisearch-store` adds a store configured with `host`, but the bundle expects `endpoint`: the container fails to build right after the install.

- In Sans: renamed by hand in `config/packages/ai.yaml`.
- Fix: one word in the recipe ([symfony/recipes#1632](https://github.com/symfony/recipes/pull/1632)).

### No way to ask for fewer results

`Store::query()` sends neither `limit` nor anything read from `$options` besides `semanticRatio`, and always asks for `retrieveVectors`. Each search returns Meilisearch's default 20 hits, each with its 1024 floats, where Sans keeps 5.

- In Sans: `array_slice()` on the results (`IngredientSearch`).
- Since then: `limit` is read by every store on the main branch ([#2865](https://github.com/symfony/ai/pull/2865), after BETA1). `retrieveVectors` is still always asked for ([#3023](https://github.com/symfony/ai/issues/3023), [PR #3026](https://github.com/symfony/ai/pull/3026)).
- Fix: read `limit` (and `retrieveVectors`) from the options, the way other bridges read theirs:

```php
$result = $this->request('POST', \sprintf('indexes/%s/search', $this->indexName), [
    // ...
    'limit' => $options['limit'] ?? 20,
    'retrieveVectors' => $options['retrieveVectors'] ?? true,
]);
```

### `setup()` takes no option

It creates the index and the embedder, and throws on any option. The searchable attributes cannot be set, so the full-text half of a hybrid search also reads the allergen metadata of the documents ("milk" matches every ingredient that contains milk).

- In Sans: left as is, the semantic half and the agent absorb the noise.
- Fix: accept the index settings in `setup()`, or call the settings route once after it:

```php
$httpClient->request('PUT', $endpoint.'/indexes/ingredients/settings/searchable-attributes', [
    'auth_bearer' => $apiKey,
    'json' => ['name', 'plural', 'name_en', 'plural_en'],
]);
```

### One request per query

A recipe has 10 to 20 names to look up, and the store reads each response before sending the next search. Meilisearch has a `multi-search` route that would take them all at once.

- In Sans: one search per name, in sequence.
- Fix: a `queryAll()` on the bridge, or lazy responses so that the HTTP client multiplexes them.

## Store: loaders and indexer

### `CsvLoader` reads one content column and keeps the metadata flat

A document gets the text of a single column, the metadata columns are copied one key each (empty cells included), and `_source` and `_row_index` are always added. An ingredient is embedded from its four names and carries its allergens as one map, and the Meilisearch bridge stores every metadata key, so the loader's own keys would end up in the index.

- In Sans: `CsvLoader` reads the file, and a transformer (`IngredientDocumentTransformer`) rebuilds the content and the metadata of each document.
- Fix: several content columns with a separator, and an option to leave the loader's keys out.

### The chunk size of an indexer cannot be configured

`DocumentProcessor` embeds and stores the documents by chunks of `$options['chunk_size'] ?? 50`, and the option can only be given in PHP, to `index()`. `ai:store:index` calls `index()` without options and `ai.indexer.*` has no node for them. The indexer cannot be decorated to add it either: the command refuses anything that is not a `SourceIndexer` or a `ConfiguredSourceIndexer`, and both are final.

- In Sans: the default is lived with, 44 embedding requests for the 2 173 ingredients where chunks of 200 would take 11 (53 seconds instead of 37). The alternative was a command of our own calling `index()` with the option, dropped to keep the native ones.
- Fix: an `options` node under `ai.indexer.*`, or `--option` on the command as `ai:store:setup` has ([#3024](https://github.com/symfony/ai/issues/3024)).

### Nothing to hook a rebuild on

`ai:store:index` adds to what the store already holds, and neither the command nor the indexer dispatches an event (the only events of the component are `PreQueryEvent` and `PostQueryEvent`, on the retriever), so the store cannot be set up and emptied from a listener.

- In Sans: `make index` chains `ai:store:setup`, `ai:store:clear --force` and `ai:store:index`.
- Fix: events around the indexing, or a `--fresh` option on the command.

## Store: Vektor

Sans started on Vektor (no server to run) and moved to Meilisearch:

- it only supports `VectorQuery`: with embeddings alone, "olive oil" did not bring back `huile d'olive` and "butter" put `margarine` first. Half full-text, half semantic fixes both;
- indexing 2,173 documents took about 2 min 30 in pure PHP, against 40 seconds with Meilisearch (embeddings included);
- `setup()` had to be called before every query.

## Agent

### The sources of a tool are far from the answer

To let the model pick only among the documents the search tool returned, the tool implements `HasSourcesInterface`, the agent needs `include_sources: true`, and the documents are read back from `$result->getMetadata()->get('sources')` as `DocumentSource` objects (a class of the similarity search bridge). It works, but nothing ties a source to the tool call or to the name it answered: `IngredientMatcher` rebuilds that link by id.

### Tool calls of one round run one after the other

The default toolbox executes tool calls in sequence. The bundle has an `execution_strategy: fiber` option on `tools`; not tried here, since each agent of Sans is told to call its tool once with all the names.

### An agent as the tool of another

The `adapter` agent checks its swaps with a tool that runs the `matcher` agent. The bundle can declare this in YAML (`tools: [{ agent: matcher }]`), but its `Subagent` tool takes one message and returns the text of the answer, with no way to pass a `response_format`: to give the adapter the allergens of each ingredient (which come from the index, not from the model), Sans wraps the matcher in its own `#[AsTool]` class (`IngredientCheck`). The price is latency: one adapter round is two model calls plus the two of the matcher.

### Streaming and structured output

`ExecutionInterface` can stream a structured answer (`asStreamedObject()`, `Progress` updates on tool calls). Sans does not use it: a swap must not be shown before the server has checked it against the reference, so there is little to show early. The page renders twice instead (the recipe, then its allergens) with a Live component polling once.

## MCP

Sans uses the tools of a remote MCP server (Open Food Facts) from an agent, with `symfony/mcp-bundle` 0.15 and `symfony/ai-mcp-tool`.

### The Flex recipe sets up a server

Installing the bundle to use its client adds an MCP *server* to the application: a `/mcp` route and a `servers.default` entry exposing every MCP service.

- In Sans: the `default` server was removed when only the client was needed. The server Sans has now (`servers.sans`) is declared by hand and only exposes the tools of `App\Mcp\`.
- Fix: a recipe that leaves both sides commented out, or two recipes.

### A PSR-17 implementation is needed, and nothing says so before the first call

The HTTP transport needs PSR-17 factories. Composer installs the bundle without one, the container builds, and the first tool call fails with "No PSR-17 request factory found".

- In Sans: `composer require nyholm/psr7`.
- Since then: fixed on the main branch ([#2828](https://github.com/symfony/ai/pull/2828), after the 0.15.0 of the bundle).
- Fix: suggest it in `composer.json`, or fail when the container is built.

### All the tools of the server, or none

`tools: [{ mcp_server: food.openfoodfacts }]` gives the agent the four tools of the server. The only option is a `prefix` for their names: there is no way to keep one tool, so the prompt has to say "never call another tool".

- Fix: an `only` (or `exclude`) list next to `prefix` ([#3025](https://github.com/symfony/ai/issues/3025), [PR #3027](https://github.com/symfony/ai/pull/3027)).

### Calling a tool without an agent works well

The connection (`mcp.client.food.server.openfoodfacts`) can be injected and `callTool()` returns the `structuredContent` of the answer as an array. This is what lets Sans read the counts in PHP instead of trusting the model to copy them.

### What is slow is the agent, not MCP

A count takes 0.2 to 0.4 second. The agent that finds the categories takes 8 to 12 seconds for two ingredients (one taxonomy search per ingredient, sometimes a second one). `execution_strategy: fiber` did not make it faster in the two runs tried. Its choice also moves between runs: baking chocolate was put in "Chocolates", "Cocoa and its products" and "Dark chocolate bar for cooking".

## Platform

### Structured output and enums (no limit here)

A backed enum in the answer class (`Role` in `SwapAnswer`) becomes an `enum` in the JSON schema and comes back as the enum case: nothing to write.
