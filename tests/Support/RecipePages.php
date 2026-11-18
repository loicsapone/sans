<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The web, in the test environment: one recipe page, nothing else.
 */
#[AsDecorator('http_client')]
final class RecipePages extends MockHttpClient
{
    // an address and not a name: the fetcher refuses what does not resolve to a public address
    public const string GRATIN = 'https://93.184.216.34/gratin';

    public function __construct()
    {
        parent::__construct(static fn (string $method, string $url): MockResponse => self::GRATIN === $url
            ? new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/recipes/jsonld.html'), ['response_headers' => ['content-type' => 'text/html']])
            : new MockResponse('', ['http_code' => 404]));
    }
}
