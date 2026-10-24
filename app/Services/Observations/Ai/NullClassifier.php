<?php

namespace App\Services\Observations\Ai;

/**
 * Used in tests and whenever ANTHROPIC_API_KEY is absent: the employee classifies manually.
 */
class NullClassifier implements ClassifierContract
{
    public function classify(int $departmentId, ?int $branchId, array $points): ClassificationBatch
    {
        return new ClassificationBatch(
            status: 'skipped',
            results: [],
            model: null,
            inputTokens: null,
            outputTokens: null,
            latencyMs: 0,
            error: null,
        );
    }
}
