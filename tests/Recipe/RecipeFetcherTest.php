<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Recipe\AnalysisFailed;
use App\Recipe\RecipeFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RecipeFetcherTest extends TestCase
{
    private function fetcher(MockResponse $response, bool $publicOnly = false): RecipeFetcher
    {
        $client = new MockHttpClient($response);

        return new RecipeFetcher($publicOnly ? new NoPrivateNetworkHttpClient($client) : $client);
    }

    public function testReturnsTheHtmlOfAPage(): void
    {
        $response = new MockResponse('<html>gratin</html>', ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);

        self::assertSame('<html>gratin</html>', $this->fetcher($response)->fetch('https://example.com/gratin'));
    }

    #[DataProvider('refusedResponses')]
    public function testRefusesWhatIsNotAnUsablePage(MockResponse $response, string $message): void
    {
        try {
            $this->fetcher($response)->fetch('https://example.com/gratin');
            self::fail('The page should have been refused.');
        } catch (AnalysisFailed $e) {
            self::assertSame('fetch', $e->stage);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function refusedResponses(): iterable
    {
        yield 'not found' => [new MockResponse('', ['http_code' => 404]), 'HTTP 404'];
        yield 'not a web page' => [new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']]), 'not a web page'];
        yield 'too large' => [new MockResponse(str_repeat('a', 3 * 1024 * 1024), ['response_headers' => ['content-type' => 'text/html']]), 'too large'];
    }

    #[DataProvider('refusedAddresses')]
    public function testRefusesAddressesThatAreNotPublicWebPages(string $url): void
    {
        $this->expectException(AnalysisFailed::class);

        $this->fetcher(new MockResponse('<html>secret</html>', ['response_headers' => ['content-type' => 'text/html']]), publicOnly: true)->fetch($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'file' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/recipe'];
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'private network' => ['http://10.0.0.5/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
    }

    public function testAllowsAPublicAddress(): void
    {
        $response = new MockResponse('<html>ok</html>', ['response_headers' => ['content-type' => 'text/html']]);

        self::assertSame('<html>ok</html>', $this->fetcher($response, publicOnly: true)->fetch('http://93.184.216.34/'));
    }
}
