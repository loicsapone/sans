<?php

declare(strict_types=1);

namespace App\Recipe;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RecipeFetcher
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        // refuses private and loopback addresses, redirects included
        #[Autowire(service: 'app.recipe_http_client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(string $url): string
    {
        if (!\in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true) || mb_strlen($url) > 2048) {
            throw new AnalysisFailed('fetch', 'Only http and https addresses are accepted.');
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 20,
                'max_redirects' => 3,
                'headers' => ['Accept' => 'text/html', 'User-Agent' => 'Sans/1.0 (recipe allergen analysis)'],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('The page is too large.');
                    }
                },
            ]);

            if (200 !== $status = $response->getStatusCode()) {
                throw new AnalysisFailed('fetch', \sprintf('The page answered with HTTP %d.', $status));
            }
            if (!str_contains($response->getHeaders(false)['content-type'][0] ?? '', 'html')) {
                throw new AnalysisFailed('fetch', 'The address is not a web page.');
            }

            return $response->getContent();
        } catch (ExceptionInterface|\RuntimeException $e) {
            if ($e instanceof AnalysisFailed) {
                throw $e;
            }

            throw new AnalysisFailed('fetch', 'The page could not be fetched: '.$e->getMessage(), $e);
        }
    }
}
