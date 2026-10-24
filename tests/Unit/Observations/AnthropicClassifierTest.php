<?php

namespace Tests\Unit\Observations;

use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Services\Observations\Ai\AnthropicClassifier;
use App\Services\Observations\Ai\ClassificationBatch;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\Seeds;
use Tests\TestCase;

/**
 * Drives AnthropicClassifier through a Guzzle MockHandler transport: no network, no tokens.
 */
class AnthropicClassifierTest extends TestCase
{
    private Department $cctv;

    private Department $maintenance;

    private ObservationCategory $category;

    private ObservationCatalogItem $item;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance');
        $this->category = ObservationCategory::create([
            'department_id' => $this->cctv->id, 'slug' => 'cameras', 'name_ar' => 'الكاميرات', 'name_en' => 'Cameras',
            'default_kind' => 'issue', 'active' => true,
        ]);
        $this->item = ObservationCatalogItem::create([
            'category_id' => $this->category->id, 'code' => 'CCTV-001', 'label_ar' => 'كاميرا معطلة',
            'label_en' => 'Camera down', 'kind' => 'issue', 'active' => true,
        ]);
    }

    /**
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function classify(array $queue, array $points = [[0, 'الكاميرا مش شغالة'], [1, 'الإضاءة ضعيفة']]): ClassificationBatch
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $classifier = new AnthropicClassifier(
            'sk-ant-test-not-a-real-key', 'claude-opus-5-5', 30,
            transporter: new GuzzleClient(['handler' => $stack]),
        );

        $batch = $classifier->classify($this->cctv->id, null, $points);

        // Never throws: every outcome is a batch.
        $this->assertInstanceOf(ClassificationBatch::class, $batch);

        return $batch;
    }

    private function message(array $content, string $stopReason = 'end_turn', array $usage = ['input_tokens' => 10, 'output_tokens' => 5]): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stopReason, 'stop_sequence' => null, 'usage' => $usage,
        ]));
    }

    private function textMessage(string $text): Response
    {
        return $this->message([['type' => 'text', 'text' => $text]]);
    }

    private function error(int $status, string $type): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error', 'error' => ['type' => $type, 'message' => 'test'],
        ]));
    }

    private function assertFailed(ClassificationBatch $batch, string $error): void
    {
        $this->assertSame('failed', $batch->status);
        $this->assertSame($error, $batch->error);
        $this->assertSame([], $batch->results);
        $this->assertGreaterThanOrEqual(0, $batch->latencyMs);
    }

    public function test_sends_structured_cached_request_and_parses_results(): void
    {
        $results = ['results' => [
            ['index' => 1, 'catalog_item_id' => null, 'category_id' => $this->category->id, 'kind' => 'issue',
                'risk_level' => 'low', 'responsible_department_id' => null, 'confidence' => 0.4, 'reason' => 'إضاءة'],
            ['index' => 0, 'catalog_item_id' => $this->item->id, 'category_id' => null, 'kind' => 'issue',
                'risk_level' => 'high', 'responsible_department_id' => $this->maintenance->id, 'confidence' => 0.9, 'reason' => 'كاميرا'],
            ['index' => 7, 'catalog_item_id' => null, 'category_id' => null, 'kind' => 'issue',
                'risk_level' => 'low', 'responsible_department_id' => null, 'confidence' => 0.1, 'reason' => 'not sent'],
        ]];
        $response = $this->message(
            [['type' => 'text', 'text' => json_encode($results)]],
            usage: ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 0],
        );

        $batch = $this->classify([$response]);

        // Request shape.
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('/v1/messages', $request->getUri()->getPath());
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        // anthropic-ai/sdk v1 sends the API key as a Bearer token (Client::apiKeyAuth()).
        $this->assertSame('Bearer sk-ant-test-not-a-real-key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(4096, $body['max_tokens']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(['results'], $body['output_config']['format']['schema']['required']);
        $this->assertCount(1, $body['system']);
        $this->assertSame('text', $body['system'][0]['type']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertStringContainsString('كاميرا معطلة', $body['system'][0]['text']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertStringContainsString('0: الكاميرا مش شغالة', $body['messages'][0]['content']);
        $this->assertStringContainsString('1: الإضاءة ضعيفة', $body['messages'][0]['content']);

        // Parsing: sanitised, sorted, unsent index dropped, tokens include cache reads.
        $this->assertSame('ok', $batch->status);
        $this->assertNull($batch->error);
        $this->assertSame('claude-opus-5-5', $batch->model);
        $this->assertSame(1000, $batch->inputTokens);
        $this->assertSame(50, $batch->outputTokens);
        $this->assertSame([0, 1], array_map(fn ($r) => $r->index, $batch->results));
        $this->assertSame($this->item->id, $batch->results[0]->catalogItemId);
        $this->assertSame($this->category->id, $batch->results[0]->categoryId);
        $this->assertSame('high', $batch->results[0]->riskLevelSlug);
        $this->assertSame($this->maintenance->id, $batch->results[0]->responsibleDepartmentId);
        $this->assertSame($this->category->id, $batch->results[1]->categoryId);
    }

    public function test_refusal_is_failed(): void
    {
        $batch = $this->classify([$this->message([], 'refusal')]);

        $this->assertFailed($batch, 'refusal');
        $this->assertSame(10, $batch->inputTokens);
    }

    public function test_max_tokens_is_failed(): void
    {
        $this->assertFailed($this->classify([$this->message([['type' => 'text', 'text' => '{"results":[']], 'max_tokens')]), 'max_tokens');
    }

    public function test_malformed_output_is_failed(): void
    {
        $this->assertFailed($this->classify([$this->textMessage('not json')]), 'malformed_output');
        $this->assertFailed($this->classify([$this->textMessage('{"items":[]}')]), 'malformed_output');
        $this->assertFailed($this->classify([$this->message([])]), 'malformed_output');
    }

    public function test_http_errors_are_mapped(): void
    {
        $this->assertFailed($this->classify([$this->error(429, 'rate_limit_error')]), 'rate_limited');
        $this->assertFailed($this->classify([$this->error(401, 'authentication_error')]), 'authentication_failed');
        $this->assertFailed($this->classify([$this->error(529, 'overloaded_error')]), 'http_529 overloaded_error');
        $this->assertFailed($this->classify([$this->error(500, 'api_error')]), 'http_500 api_error');
        $this->assertFailed($this->classify([$this->error(400, 'invalid_request_error')]), 'http_400 invalid_request_error');

        // One attempt per call: no SDK retries keep the employee waiting.
        $this->assertCount(5, $this->history);
    }

    public function test_transport_failures_are_mapped(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');

        $this->assertFailed($this->classify([
            new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', $request),
        ]), 'timeout');
        $this->assertFailed($this->classify([
            new ConnectException('cURL error 7: Failed to connect to api.anthropic.com port 443', $request),
        ]), 'connection_failed');
    }

    public function test_no_points_skips_without_a_request(): void
    {
        $batch = $this->classify([], []);

        $this->assertSame('skipped', $batch->status);
        $this->assertSame([], $this->history);
    }
}
