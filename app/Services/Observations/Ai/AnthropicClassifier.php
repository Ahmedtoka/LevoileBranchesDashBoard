<?php

namespace App\Services\Observations\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * Claude driver of the observation classifier (spec section 8).
 *
 * الـ system prompt (القواعد + فئات وكتالوج الإدارة + درجات الريسك + الإدارات + أمثلة الأدمن) ثابت
 * لكل إدارة لحد ما الكتالوج أو قرارات الأدمن تتغير، فبيتبعت في block واحد عليه cache_control
 * (ephemeral) عشان الطلبات المتكررة تقرا الـ prefix من الكاش. أي حاجة متغيرة (الفرع، التاريخ، النقط)
 * بتتحط في رسالة الـ user بس، عشان ما تكسرش الكاش.
 *
 * The system block (rules, the department's categories and catalog, risk levels, departments,
 * admin examples) is stable per department until the catalog or admin decisions change, so it is
 * sent as one cached block (cache_control ephemeral). Everything volatile (branch, date, points)
 * lives in the user message only, so it never invalidates the cached prefix.
 *
 * The call is synchronous (the employee is waiting): one attempt, no SDK retries, a hard HTTP
 * timeout on the transport, and every failure is returned as status "failed" instead of thrown.
 */
class AnthropicClassifier implements ClassifierContract
{
    private const MAX_TOKENS = 4096;

    /** Server-side fallback on a policy decline ("default" form of the fallbacks parameter). */
    private const BETAS = ['server-side-fallback-2026-07-01'];

    private Client $client;

    public function __construct(
        string $apiKey,
        private readonly string $model,
        int $timeout,
        private readonly PromptBuilder $prompts = new PromptBuilder,
    ) {
        $this->client = new Client(
            apiKey: $apiKey,
            requestOptions: [
                // The SDK does not enforce `timeout` itself; the PSR-18 transport does.
                'timeout' => (float) $timeout,
                'maxRetries' => 0,
                'transporter' => new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min(10, $timeout)]),
            ],
        );
    }

    public function classify(int $departmentId, ?int $branchId, array $points): ClassificationBatch
    {
        if ($points === []) {
            return new ClassificationBatch('skipped', [], null, null, null, 0, null);
        }

        $started = hrtime(true);

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $this->prompts->user($branchId, $points)]],
                model: $this->model,
                fallbacks: 'default',
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $this->prompts->schema()],
                ],
                system: [[
                    'type' => 'text',
                    'text' => $this->prompts->system($departmentId),
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                betas: self::BETAS,
            );

            $usage = $message->usage;
            // All prompt tokens of the call, cached or not.
            $inputTokens = $usage->inputTokens + ($usage->cacheReadInputTokens ?? 0) + ($usage->cacheCreationInputTokens ?? 0);

            if ($message->stopReason === 'refusal' || $message->stopReason === 'max_tokens') {
                return $this->failed($started, $message->stopReason, $message->model, $inputTokens, $usage->outputTokens);
            }

            $text = null;
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text = $block->text;
                    break;
                }
            }

            $decoded = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
                return $this->failed($started, 'malformed_output', $message->model, $inputTokens, $usage->outputTokens);
            }

            // Only indexes that were actually sent.
            $sent = array_map(fn ($point) => (int) array_values($point)[0], $points);
            $results = array_values(array_filter(
                $this->prompts->sanitise($decoded['results'], $departmentId),
                fn (ClassificationResult $r) => in_array($r->index, $sent, true),
            ));

            return new ClassificationBatch(
                status: 'ok',
                results: $results,
                model: $message->model,
                inputTokens: $inputTokens,
                outputTokens: $usage->outputTokens,
                latencyMs: $this->elapsedMs($started),
                error: null,
            );
        } catch (APITimeoutException) {
            return $this->failed($started, 'timeout');
        } catch (RateLimitException) {
            return $this->failed($started, 'rate_limited');
        } catch (AuthenticationException) {
            return $this->failed($started, 'authentication_failed');
        } catch (APIStatusException $e) {
            return $this->failed($started, trim('http_'.$e->status.' '.($e->type?->value ?? '')));
        } catch (APIConnectionException $e) {
            // The SDK wraps transport errors; a Guzzle timeout arrives as "cURL error 28 ... timed out".
            $cause = strtolower((string) $e->getPrevious()?->getMessage());

            return $this->failed($started, str_contains($cause, 'timed out') || str_contains($cause, 'error 28') ? 'timeout' : 'connection_failed');
        } catch (\JsonException) {
            return $this->failed($started, 'malformed_output');
        } catch (\Throwable $e) {
            return $this->failed($started, 'unexpected: '.class_basename($e));
        }
    }

    private function failed(int $started, string $error, ?string $model = null, ?int $inputTokens = null, ?int $outputTokens = null): ClassificationBatch
    {
        Log::warning('Observation classifier failed', ['error' => $error, 'model' => $model ?? $this->model]);

        return new ClassificationBatch(
            status: 'failed',
            results: [],
            model: $model ?? $this->model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            latencyMs: $this->elapsedMs($started),
            error: $error,
        );
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
