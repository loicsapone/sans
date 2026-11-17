<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testTheHomePageInvitesToPasteARecipeLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'without what you can’t eat');
        self::assertSelectorExists('form input[name="url"][type="url"]');
        self::assertSelectorTextContains('#how', 'Paste a link');
        self::assertSelectorTextContains('.disclaimer', 'always check the packaging');
    }
}
