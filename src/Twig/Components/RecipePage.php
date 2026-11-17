<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\Allergen;
use App\Recipe\Analysis;
use App\Recipe\AnalysisFailed;
use App\Recipe\AnalysisStore;
use App\Recipe\Recipe;
use App\Recipe\RecipeAnalyzer;
use App\Recipe\RecipeView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The whole page: the form, then the recipe with its allergens (or the home page, or why the recipe could not be read).
 */
#[AsLiveComponent]
final class RecipePage
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $url = '';

    /** @var list<string> the values of the allergens the visitor cooks without */
    #[LiveProp]
    public array $avoided = [];

    /** @var 'yours'|'original' */
    #[LiveProp]
    public string $version = 'yours';

    /** @var 'analysing'|null set while the recipe is shown and its allergens are still being worked out */
    #[LiveProp]
    public ?string $stage = null;

    #[LiveProp(useSerializerForHydration: true)]
    public ?Recipe $recipe = null;

    #[LiveProp]
    public ?string $failedStage = null;

    #[LiveProp]
    public ?string $failedMessage = null;

    private ?Analysis $analysis = null;
    private bool $loaded = false;
    private bool $justRead = false;

    public function __construct(
        private readonly AnalysisStore $store,
        private readonly RecipeAnalyzer $analyzer,
    ) {
    }

    /**
     * Reads the page: the recipe is shown at once, its allergens follow (see analyseWhenReady()).
     */
    #[LiveAction]
    public function read(): void
    {
        $this->url = mb_trim($this->url);
        $this->avoided = [];
        $this->version = 'yours';
        $this->stage = $this->recipe = $this->failedStage = $this->failedMessage = null;
        $this->analysis = null;

        try {
            $this->analysis = $this->store->find($this->url);
            if (null === $this->analysis) {
                $this->recipe = $this->analyzer->read($this->url);
                $this->stage = 'analysing';
                $this->justRead = true;
            }
        } catch (AnalysisFailed $e) {
            $this->failedStage = $e->stage;
            $this->failedMessage = $e->getMessage();
        }
        $this->loaded = true;
    }

    /**
     * While the recipe is shown without its allergens, the browser asks for a new render: this is the one that does the work.
     */
    #[PreReRender]
    public function analyseWhenReady(): void
    {
        if ($this->justRead || 'analysing' !== $this->stage || null === $this->recipe) {
            return;
        }

        try {
            $this->analysis = $this->analyzer->analyse($this->recipe);
            $this->store->save($this->url, $this->analysis);
        } catch (AnalysisFailed $e) {
            $this->failedStage = $e->stage;
            $this->failedMessage = $e->getMessage();
        }
        $this->stage = $this->recipe = null;
        $this->loaded = true;
    }

    public function isAnalysing(): bool
    {
        return 'analysing' === $this->stage && null === $this->failedStage;
    }

    #[LiveAction]
    public function toggleAllergen(#[LiveArg] string $allergen): void
    {
        $choice = Allergen::tryFrom($allergen);
        if (null === $choice || $this->isAnalysing()) {
            return;
        }

        $this->avoided = \in_array($choice->value, $this->avoided, true)
            ? array_values(array_diff($this->avoided, [$choice->value]))
            : [...$this->avoided, $choice->value];
        $this->version = 'yours';
    }

    #[LiveAction]
    public function setVersion(#[LiveArg] string $version): void
    {
        $this->version = 'original' === $version ? 'original' : 'yours';
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->avoided = [];
        $this->version = 'yours';
    }

    public function view(): ?RecipeView
    {
        $analysis = $this->analysis();
        if (null === $analysis) {
            return $this->isAnalysing() && null !== $this->recipe ? RecipeView::withoutAnalysis($this->recipe, $this->url) : null;
        }

        $avoided = array_values(array_filter(array_map(Allergen::tryFrom(...), $this->avoided)));

        return RecipeView::of($analysis, $this->url, $avoided, null, 'original' === $this->version);
    }

    /**
     * What to tell the visitor when the recipe could not be read.
     *
     * @return array{title: string, text: string, tips: list<string>}|null
     */
    public function failure(): ?array
    {
        return match ($this->failedStage) {
            null => null,
            'fetch' => [
                'title' => 'Sans couldn’t open this page',
                'text' => (string) $this->failedMessage,
                'tips' => ['Check the link starts with https://', 'Make sure the page is public, without a login'],
            ],
            'extract' => [
                'title' => 'Sans couldn’t find a recipe on this page',
                'text' => 'It needs a page with an ingredient list. A category page, a video or a page behind a login won’t work.',
                'tips' => ['Open the recipe itself, then copy its link', 'Check the link starts with https://'],
            ],
            default => [
                'title' => 'Sans couldn’t analyse this recipe',
                'text' => 'Something went wrong while reading its ingredients.',
                'tips' => ['Try again in a moment', 'If it keeps failing, try another recipe'],
            ],
        };
    }

    private function analysis(): ?Analysis
    {
        if (!$this->loaded && null === $this->failedStage && null === $this->stage && '' !== $this->url) {
            $this->analysis = $this->store->find($this->url);
        }
        $this->loaded = true;

        return $this->analysis;
    }
}
