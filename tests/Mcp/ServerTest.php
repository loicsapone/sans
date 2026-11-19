<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ServerTest extends WebTestCase
{
    public function testTheServerListsTheToolsOfSans(): void
    {
        $client = static::createClient();

        $this->post($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
        self::assertResponseIsSuccessful();
        $session = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertSame('sans', $this->answer($client)['result']['serverInfo']['name']);

        $this->post($client, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
        self::assertResponseIsSuccessful();
        $tools = array_column($this->answer($client)['result']['tools'], null, 'name');

        self::assertSame(['analyze_recipe', 'adapt_recipe', 'check_ingredients'], array_keys($tools));
        self::assertSame(['url', 'avoid'], $tools['adapt_recipe']['inputSchema']['required']);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(KernelBrowser $client, array $message, string $session = ''): void
    {
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_SESSION_ID' => $session,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(KernelBrowser $client): array
    {
        $content = (string) $client->getInternalResponse()->getContent();
        if (preg_match('/^data: (.+)$/m', $content, $event)) {
            $content = $event[1];
        }

        return json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }
}
