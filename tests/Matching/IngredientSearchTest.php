<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Matching\IngredientSearch;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Meilisearch\Store;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class IngredientSearchTest extends TestCase
{
    public function testAsksForTheWordsAndTheMeaningOfEachNameAndKeepsTheFiveFirstAsSources(): void
    {
        $hits = [];
        foreach (['beurre', 'beurre doux', 'beurre salé', 'beurre clarifié', 'beurre de cacahuète', 'noix'] as $i => $name) {
            $hits[] = ['id' => (string) ($i + 1), 'name' => $name, 'name_en' => 'en '.$name, 'allergens' => ['milk' => 'always'], '_rankingScore' => 1 - $i / 10];
        }

        $sent = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $hits): JsonMockResponse {
            $sent = json_decode($options['body'], true);

            return new JsonMockResponse(['hits' => $hits]);
        }, 'http://meilisearch:7700');

        $vectorizer = $this->createStub(VectorizerInterface::class);
        $vectorizer->method('vectorize')->willReturn([new Vector([1.0, 0.1])]);

        $tool = new IngredientSearch($vectorizer, new Store($httpClient, 'ingredients', dimensions: 2));
        $found = $tool(['une noix de beurre']);

        self::assertSame('une noix de beurre', $sent['q']);
        self::assertSame([1.0, 0.1], $sent['vector']);
        self::assertSame(0.5, $sent['hybrid']['semanticRatio']);

        self::assertSame(['id' => '1', 'name' => 'beurre', 'name_en' => 'en beurre'], $found['une noix de beurre'][0]);
        self::assertCount(5, $found['une noix de beurre']);
        self::assertCount(5, $tool->getSourceCollection());
    }
}
