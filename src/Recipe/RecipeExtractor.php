<?php

declare(strict_types=1);

namespace App\Recipe;

use Dom\HTMLDocument;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the recipe of a page: the schema.org data most recipe sites publish, or the model when there is none.
 */
final readonly class RecipeExtractor
{
    private const MAX_TEXT = 15000;

    public function __construct(
        #[Autowire(service: 'ai.agent.extractor')]
        private AgentInterface $agent,
    ) {
    }

    public function extract(string $html): Recipe
    {
        $document = HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        $recipe = $this->fromJsonLd($document) ?? $this->fromText($document);
        if ([] === $recipe->ingredients) {
            throw new AnalysisFailed('extract', 'No recipe was found on this page.');
        }

        return $recipe;
    }

    private function fromJsonLd(HTMLDocument $document): ?Recipe
    {
        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            try {
                $data = json_decode((string) $script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $node = $this->findRecipe($data);
            $ingredients = null === $node ? [] : $this->texts($node['recipeIngredient'] ?? []);
            if ([] !== $ingredients) {
                return new Recipe($this->text($node['name'] ?? ''), $ingredients, $this->steps($node['recipeInstructions'] ?? []));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecipe(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        if (\in_array('Recipe', (array) ($data['@type'] ?? []), true)) {
            return $data;
        }

        foreach ($data['@graph'] ?? $data as $item) {
            if (null !== $found = $this->findRecipe($item)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function steps(mixed $instructions): array
    {
        if (!\is_array($instructions)) {
            return $this->texts($instructions);
        }
        if (isset($instructions['itemListElement'])) {
            return $this->steps($instructions['itemListElement']);
        }
        if (isset($instructions['text'])) {
            return $this->texts($instructions['text']);
        }

        $steps = [];
        foreach ($instructions as $item) {
            array_push($steps, ...$this->steps($item));
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    private function texts(mixed $value): array
    {
        return array_values(array_filter(array_map($this->text(...), (array) $value)));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? mb_trim(html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5)) : '';
    }

    private function fromText(HTMLDocument $document): Recipe
    {
        foreach ($document->querySelectorAll('script, style, noscript, nav, header, footer, form') as $node) {
            $node->remove();
        }
        $text = mb_substr((string) preg_replace('/\s+/u', ' ', (string) $document->body?->textContent), 0, self::MAX_TEXT);

        try {
            $recipe = $this->agent->call($text, ['response_format' => Recipe::class])->getResult()->getContent();
        } catch (ExceptionInterface $e) {
            throw new AnalysisFailed('extract', 'The recipe could not be read from this page.', $e);
        }

        return $recipe instanceof Recipe ? $recipe : new Recipe();
    }
}
