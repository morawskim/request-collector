<?php

namespace Mmo\RequestCollector;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Mmo\RequestCollector\Guzzle\GuzzleMiddleware;
use Mmo\RequestCollector\SanitizeData\JsonStringSanitizeData;
use Mmo\RequestCollector\SanitizeData\PsrMessageSanitizeDataInterface;
use Mmo\RequestCollector\Test\GuzzleUtils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function GuzzleHttp\choose_handler;

class GuzzleMiddlewareAcceptanceTest extends TestCase
{
    public function testMiddleware(): void
    {
        $requestCollector = new RequestCollector();
        $requestCollector->enable();
        $client = $this->getHttpClient($requestCollector);

        $response = $client->request('GET', \TestHelper::buildJsonPlaceholderUrl('/users'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonFile(__DIR__ . '/_fixture/jsonplaceholder-users.json', $response->getBody());
        $this->assertCount(1, $requestCollector->getAllStoredItems());

        $this->assertEquals(
            \TestHelper::replaceHostnamePlaceholderWithCurrentValue(file_get_contents(__DIR__ . '/_fixture/request-collector-request.txt')),
            str_replace("\r", '', $requestCollector->getAllStoredItems()[0]->getRequest())
        );


        $this->assertStringContainsString('HTTP/1.1 200 OK', $requestCollector->getAllStoredItems()[0]->getResponse());
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $requestCollector->getAllStoredItems()[0]->getResponse());

        $this->assertStringEqualsFile(
            __DIR__ . '/_fixture/request-collector-response.txt',
            explode("\n\n", str_replace("\r", '', $requestCollector->getAllStoredItems()[0]->getResponse()), 2)[1]
        );
    }

    public function testSkipRequestCollectorOption(): void
    {
        $requestCollector = new RequestCollector();
        $requestCollector->enable();
        $client = $this->getHttpClient($requestCollector);

        $client->request('GET', \TestHelper::buildJsonPlaceholderUrl('/users'), [GuzzleMiddleware::GUZZLE_OPTION_SKIP_REQUEST_COLLECTOR => true]);
        $client->request('GET', \TestHelper::buildJsonPlaceholderUrl('/users'));

        $this->assertCount(1, $requestCollector->getAllStoredItems());
    }

    public function testPostRequest(): void
    {
        $requestCollector = new RequestCollector();
        $requestCollector->enable();
        $client = $this->getHttpClient($requestCollector);

        $client->request('POST', \TestHelper::buildJsonPlaceholderUrl('/comments'), [
            'json' => [
                "postId" => 1,
                "id" => 11,
                "name" => "id labore ex et quam laborum",
                "email" => "Eliseo@gardner.biz",
                "body" => "laudantium enim quasi est quidem magnam voluptate ipsam eos\ntempora quo necessitatibus\ndolor quam autem quasi\nreiciendis et nam sapiente accusantium"
            ],
            GuzzleMiddleware::GUZZLE_OPTION_SANITIZE_SERVICE => $this->createPsrMessageSanitizeService(),
        ]);

        $this->assertCount(1, $requestCollector->getAllStoredItems());
        $this->assertEquals(
            \TestHelper::replaceHostnamePlaceholderWithCurrentValue(file_get_contents(__DIR__ . '/_fixture/guzzle-post-request.txt')),
            str_replace("\r", '', $requestCollector->getAllStoredItems()[0]->getRequest())
        );

        $this->assertStringContainsString('HTTP/1.1 201 Created', $requestCollector->getAllStoredItems()[0]->getResponse());
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $requestCollector->getAllStoredItems()[0]->getResponse());
        $this->assertStringContainsString(
            'Location: ',
            $requestCollector->getAllStoredItems()[0]->getResponse()
        );

        $this->assertStringEqualsFile(
            __DIR__ . '/_fixture/guzzle-post-response.txt',
            explode("\n\n", str_replace("\r", '', $requestCollector->getAllStoredItems()[0]->getResponse()), 2)[1]
        );
    }

    private function getHttpClient(RequestCollector $requestCollector): Client
    {
        $stack = new HandlerStack();
        $stack->setHandler(choose_handler());
        $stack->push(GuzzleMiddleware::requestCollector($requestCollector));

        return new Client(['handler' => $stack, 'headers' => ['User-Agent' => 'RequestCollector/test']]);
    }

    private function createPsrMessageSanitizeService(): PsrMessageSanitizeDataInterface
    {
        return new class(new JsonStringSanitizeData(['email'])) implements PsrMessageSanitizeDataInterface {
            private JsonStringSanitizeData $jsonStringSanitizeData;

            public function __construct(JsonStringSanitizeData $jsonStringSanitizeData)
            {
                $this->jsonStringSanitizeData = $jsonStringSanitizeData;
            }

            public function sanitizeRequestData(RequestInterface $request): RequestInterface
            {
                return $request->withBody(GuzzleUtils::streamFor(
                    $this->jsonStringSanitizeData->sanitizeData((string) $request->getBody())
                ));
            }

            public function sanitizeResponseData(ResponseInterface $response): ResponseInterface
            {
                return $response->withBody(GuzzleUtils::streamFor(
                    $this->jsonStringSanitizeData->sanitizeData((string) $response->getBody())
                ));
            }
        };
    }
}
