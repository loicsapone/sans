<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\Allergen;
use App\Recipe\Analysis;
use App\Recipe\AnalysisFailed;
use App\Recipe\AnalysisStore;
use App\Recipe\Recipe;
use App\Recipe\RecipeAdapter;
use App\Recipe\RecipeAnalyzer;
use App\Recipe\RecipeView;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
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

    /** @var 'analysing'|'adapting'|null set while the recipe is shown and its allergens or its swaps are still being worked out */
    #[LiveProp]
    public ?string $stage = null;

    #[LiveProp(useSerializerForHydration: true)]
    public ?Recipe $recipe = null;

    #[LiveProp]
    public bool $adaptationFailed = false;

    #[LiveProp]
    public ?string $failedStage = null;

    #[LiveProp]
    public ?string $failedMessage = null;

    private ?Analysis $analysis = null;
    private bool $loaded = false;
    private bool $justRead = false;
    private bool $justToggled = false;

    public function __construct(
        private readonly AnalysisStore $store,
        private readonly RecipeAnalyzer $analyzer,
        private readonly RecipeAdapter $adapter,
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
        $this->adaptationFailed = false;
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

    /**
     * Same trick once the visitor has chosen allergens: the swaps are found by the render that follows.
     */
    #[PreReRender]
    public function adaptWhenReady(): void
    {
        if ($this->justToggled || !$this->isAdapting()) {
            return;
        }

        $analysis = $this->analysis();
        try {
            if (null !== $analysis) {
                $avoided = $this->avoidedAllergens();
                $this->store->saveAdaptation($this->url, $avoided, $this->adapter->adapt($analysis, $avoided));
            }
        } catch (PlatformException|AgentException|HttpException) {
            $this->adaptationFailed = true;
        }
        $this->stage = null;
    }

    public function isAdapting(): bool
    {
        return 'adapting' === $this->stage;
    }

    #[LiveAction]
    public function toggleAllergen(#[LiveArg] string $allergen): void
    {
        $choice = Allergen::tryFrom($allergen);
        if (null === $choice || $this->isAnalysing() || $this->isAdapting()) {
            return;
        }

        $this->avoided = \in_array($choice->value, $this->avoided, true)
            ? array_values(array_diff($this->avoided, [$choice->value]))
            : [...$this->avoided, $choice->value];
        $this->version = 'yours';
        $this->adaptationFailed = false;

        $avoided = $this->avoidedAllergens();
        if ([] !== $avoided && null !== $this->analysis() && null === $this->store->findAdaptation($this->url, $avoided)) {
            $this->stage = 'adapting';
            $this->justToggled = true;
        }
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
        $this->adaptationFailed = false;
        $this->stage = null;
    }

    public function view(): ?RecipeView
    {
        $analysis = $this->analysis();
        if (null === $analysis) {
            return $this->isAnalysing() && null !== $this->recipe ? RecipeView::withoutAnalysis($this->recipe, $this->url) : null;
        }

        $avoided = $this->avoidedAllergens();
        $adaptation = [] === $avoided || $this->isAdapting() ? null : $this->store->findAdaptation($this->url, $avoided);

        return RecipeView::of($analysis, $this->url, $avoided, $adaptation, 'original' === $this->version, $this->isAdapting());
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

    /**
     * @return list<Allergen>
     */
    private function avoidedAllergens(): array
    {
        return array_values(array_filter(array_map(Allergen::tryFrom(...), $this->avoided)));
    }

    private function analysis(): ?Analysis
    {
        if (!$this->loaded && null === $this->failedStage && 'analysing' !== $this->stage && '' !== $this->url) {
            $this->analysis = $this->store->find($this->url);
        }
        $this->loaded = true;

        return $this->analysis;
    }
}
