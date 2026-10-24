<?php

namespace App\Services\Observations\Ai;

/**
 * Classifies free-text observation points of one reporting department (spec section 8).
 * Implementations never throw: any failure comes back as a batch with status "failed".
 */
interface ClassifierContract
{
    /**
     * @param  array<int, array{0:int, 1:string}>  $points  [[index, text], ...]
     */
    public function classify(int $departmentId, ?int $branchId, array $points): ClassificationBatch;
}
