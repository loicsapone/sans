<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testTheLogoIsAnImageNamedSansWhoseAnimatedWordsAreHiddenFromScreenReaders(): void
    {
        $html = (string) $this->renderTwigComponent('Logo');

        self::assertStringContainsString('role="img" aria-label="Sans"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testTheToggleIsARealButtonThatSaysWhatItDoes(): void
    {
        $html = (string) $this->renderTwigComponent('AllergenToggle', ['label' => 'Gluten']);

        self::assertStringContainsString('<button', $html);
        self::assertStringContainsString('type="button"', $html);
        self::assertStringContainsString('aria-pressed="false"', $html);
        self::assertStringContainsString('aria-label="Cook without gluten"', $html);
        self::assertStringNotContainsString('aria-busy', $html);
    }

    public function testTheToggleShowsItsActiveAndBusyStates(): void
    {
        $html = (string) $this->renderTwigComponent('AllergenToggle', ['label' => 'Tree nuts', 'pressed' => true, 'busy' => true]);

        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('aria-busy="true"', $html);
        self::assertStringContainsString('aria-label="Cook without tree nuts"', $html);
    }
}
