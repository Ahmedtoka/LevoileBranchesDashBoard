<?php

namespace App\Services\Observations\Ai;

/**
 * The classifier's suggestion for one point, already sanitised against the database.
 */
final readonly class ClassificationResult
{
    public function __construct(
        public int $index,
        public ?int $catalogItemId,
        public ?int $categoryId,
        public string $kind,
        public string $riskLevelSlug,
        public ?int $responsibleDepartmentId,
        public float $confidence,
        public string $reason,
    ) {}

    /**
     * The spec section 8 shape, stored in ai_classifications.suggested.
     *
     * @return array{index:int, catalog_item_id:?int, category_id:?int, kind:string, risk_level:string, responsible_department_id:?int, confidence:float, reason:string}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'catalog_item_id' => $this->catalogItemId,
            'category_id' => $this->categoryId,
            'kind' => $this->kind,
            'risk_level' => $this->riskLevelSlug,
            'responsible_department_id' => $this->responsibleDepartmentId,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }
}
