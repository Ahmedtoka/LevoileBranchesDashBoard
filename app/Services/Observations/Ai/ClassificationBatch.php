<?php

namespace App\Services\Observations\Ai;

/**
 * One classifier call: its outcome, the per-point results and the call metadata
 * persisted on every ai_classifications row of the batch.
 */
final readonly class ClassificationBatch
{
    /**
     * @param  'ok'|'failed'|'skipped'  $status
     * @param  ClassificationResult[]  $results
     */
    public function __construct(
        public string $status,
        public array $results,
        public ?string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
        public ?string $error,
    ) {}
}
