# Limits met with the Symfony AI components

Notes taken while building Sans on `symfony/ai` 1.0.0-BETA1. Each entry says what got in the way, what Sans does about it, and what a fix could look like. None of them is a blocker.

## Store: Meilisearch bridge

### The Flex recipe writes an option the bundle refuses

`composer require symfony/ai-meilisearch-store` adds a store configured with `host`, but the bundle expects `endpoint`: the container fails to build right after the install.

- In Sans: renamed by hand in `config/packages/ai.yaml`.
- Fix: one word in the recipe.

### No way to ask for fewer results

`Store::query()` sends neither `limit` nor anything read from `$options` besides `semanticRatio`, and always asks for `retrieveVectors`. Each search returns Meilisearch's default 20 hits, each with its 1024 floats, where Sans keeps 5.

- In Sans: `array_slice()` on the results (`IngredientSearch`).
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

## Platform

### Structured output and enums (no limit here)

A backed enum in the answer class (`Role` in `SwapAnswer`) becomes an `enum` in the JSON schema and comes back as the enum case: nothing to write.
