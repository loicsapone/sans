<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enum\Allergen;
use App\Enum\Verdict;
use App\Recipe\Analysis;
use App\Recipe\AnalysisFailed;
use App\Recipe\AnalysisStore;
use App\Recipe\IngredientCheck;
use App\Recipe\RecipeAdapter;
use App\Recipe\RecipeAnalyzer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

final readonly class RecipeTools
{
    public function __construct(
        private AnalysisStore $store,
        private RecipeAnalyzer $analyzer,
        private RecipeAdapter $adapter,
        private IngredientCheck $check,
    ) {
    }

    /**
     * @param string $url the public address of a recipe page
     *
     * @return array{title: string, servings: int|null, minutes: int|null, ingredients: list<array{line: int, text: string, optional: bool, allergens: array<string, string>}>, steps: list<string>, allergens: array<string, array{verdict: string, ingredients: list<string>}>, not_detected: list<string>}
     */
    #[McpTool(name: 'analyze_recipe', description: 'Reads the recipe of a web page and says which of the 14 EU regulatory allergens it contains, with the ingredients responsible. A verdict is "detected", "depends" (only some products carry it: read the label) or "incomplete" (an ingredient could not be identified). Verdicts come from a reference list, not from a language model.')]
    public function analyze(string $url): array
    {
        $analysis = $this->analysis($url);
        $carried = $analysis->linesCarrying(Allergen::cases());

        $ingredients = [];
        foreach ($analysis->lines as $position => $line) {
            $ingredients[] = [
                'line' => $position,
                'text' => $line->raw,
                'optional' => $line->optional,
                'allergens' => array_map(static fn ($certainty): string => $certainty->value, $carried[$position] ?? []),
            ];
        }

        $allergens = $notDetected = [];
        foreach ($analysis->verdicts as $verdict) {
            if (Verdict::NotDetected === $verdict->verdict) {
                $notDetected[] = $verdict->allergen->value;
            } else {
                $allergens[$verdict->allergen->value] = ['verdict' => $verdict->verdict->value, 'ingredients' => $verdict->ingredients];
            }
        }

        return [
            'title' => $analysis->recipe->title,
            'servings' => $analysis->recipe->servings,
            'minutes' => $analysis->recipe->minutes,
            'ingredients' => $ingredients,
            'steps' => $analysis->recipe->steps,
            'allergens' => $allergens,
            'not_detected' => $notDetected,
        ];
    }

    /**
     * @param string       $url   the public address of a recipe page
     * @param list<string> $avoid the allergens to cook without, among: gluten, crustaceans, eggs, fish, peanuts, soybeans, milk, tree-nuts, celery, mustard, sesame, sulphites, lupin, molluscs
     *
     * @return array{title: string, swaps: list<array{line: int, was: string, quantity: string, name: string, role: string, removes: list<string>}>, steps: list<array{text: string, adapted: bool}>, without_swap: list<array{line: int, text: string}>}
     */
    #[McpTool(name: 'adapt_recipe', description: 'Adapts the recipe of a web page so that it avoids the given allergens: a swap for each ingredient line that carries one, and the steps rewritten to match. Every swap was checked against the reference list; the lines in "without_swap" have no safe swap, or only may carry the allergen, and need the cook to read the label.')]
    public function adapt(string $url, array $avoid): array
    {
        $avoided = [];
        foreach ($avoid as $value) {
            $avoided[] = Allergen::tryFrom($value) ?? throw new ToolCallException(\sprintf('"%s" is not one of the allergens: %s.', $value, implode(', ', array_column(Allergen::cases(), 'value'))));
        }
        if ([] === $avoided) {
            throw new ToolCallException('Give at least one allergen to avoid.');
        }

        $analysis = $this->analysis($url);
        $adaptation = $this->store->findAdaptation($url, $avoided);
        if (null === $adaptation) {
            try {
                $adaptation = $this->adapter->adapt($analysis, $avoided);
            } catch (PlatformException|AgentException|HttpException $e) {
                throw new ToolCallException('The recipe could not be adapted, try again in a moment.', 0, $e);
            }
            $this->store->saveAdaptation($url, $avoided, $adaptation);
        }

        $swaps = [];
        foreach ($adaptation->swaps as $swap) {
            $swaps[] = [
                'line' => $swap->line,
                'was' => $analysis->lines[$swap->line]->raw,
                'quantity' => $swap->quantity,
                'name' => $swap->name,
                'role' => $swap->role->value,
                'removes' => array_column($swap->allergens, 'value'),
            ];
        }

        return [
            'title' => $analysis->recipe->title,
            'swaps' => $swaps,
            'steps' => array_map(static fn ($step): array => ['text' => $step->text, 'adapted' => $step->adapted], $adaptation->steps),
            'without_swap' => array_map(static fn (int $line): array => ['line' => $line, 'text' => $analysis->lines[$line]->raw], $adaptation->failed),
        ];
    }

    /**
     * @param list<string> $names plain ingredient names, in French or in English ("butter", "farine de riz")
     *
     * @return array<string, array{known: bool, allergens: array<string, string>}>
     */
    #[McpTool(name: 'check_ingredients', description: 'Looks up ingredient names in the reference list of Sans. Says, for each name, whether it is known and which of the 14 EU allergens it always contains ("always") or contains in some products only ("depends").')]
    public function check(array $names): array
    {
        try {
            return ($this->check)($names);
        } catch (PlatformException|AgentException|HttpException $e) {
            throw new ToolCallException('The ingredients could not be looked up, try again in a moment.', 0, $e);
        }
    }

    private function analysis(string $url): Analysis
    {
        $url = mb_trim($url);
        $analysis = $this->store->find($url);
        if (null === $analysis) {
            try {
                $analysis = $this->analyzer->analyse($this->analyzer->read($url));
            } catch (AnalysisFailed $e) {
                throw new ToolCallException(\sprintf('The analysis failed at the "%s" stage: %s', $e->stage, $e->getMessage()), 0, $e);
            }
            $this->store->save($url, $analysis);
        }

        return $analysis;
    }
}
