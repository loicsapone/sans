<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The web, in the test environment: one recipe page, nothing else.
 */
final class RecipePages
{
    public function __invoke(string $method, string $url): ResponseInterface
    {
        if ('https://recipes.test/gratin' !== $url) {
            return new MockResponse('', ['http_code' => 404]);
        }

        return new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/recipes/jsonld.html'), ['response_headers' => ['content-type' => 'text/html']]);
    }
}
