<?php

namespace Tests\Unit\Observations;

use App\Services\Observations\Ai\AnthropicClassifier;
use App\Services\Observations\Ai\ClassificationBatch;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\Ai\NullClassifier;
use Tests\TestCase;

class NullClassifierTest extends TestCase
{
    public function test_null_classifier_skips(): void
    {
        $batch = (new NullClassifier)->classify(1, null, [[0, 'الموظفة اتأخرت عن الفتح']]);

        $this->assertInstanceOf(ClassificationBatch::class, $batch);
        $this->assertSame('skipped', $batch->status);
        $this->assertSame([], $batch->results);
        $this->assertNull($batch->model);
        $this->assertNull($batch->inputTokens);
        $this->assertNull($batch->outputTokens);
        $this->assertSame(0, $batch->latencyMs);
        $this->assertNull($batch->error);
    }

    public function test_container_binds_driver_by_key(): void
    {
        config(['services.anthropic.key' => null]);
        $this->assertInstanceOf(NullClassifier::class, app(ClassifierContract::class));

        // Constructing the client makes no network call.
        config(['services.anthropic.key' => 'sk-ant-test-not-a-real-key']);
        $this->assertInstanceOf(AnthropicClassifier::class, app(ClassifierContract::class));
    }
}
