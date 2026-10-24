<?php

namespace App\Services\Observations;

use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;

/**
 * Pure policy lookup for one observation item (spec section 7). Never writes to the database.
 *
 * Risk / responsible department / cc: catalog item -> category default -> the item's own value
 * (the employee-confirmed AI suggestion). The catalog item's escalation_rules are then applied
 * to the numeric extra_values of the item.
 */
class RiskResolver
{
    /**
     * @param  array  $item  keys used: risk_level_slug, responsible_department_id, cc_department_ids, kind, extra_values
     * @return array{risk_level_id:?int, responsible_department_id:?int, cc_department_ids:array, sla_hours:?int, kind:string}
     */
    public function resolve(array $item, ?ObservationCatalogItem $catalog, ?ObservationCategory $category): array
    {
        $risk = $this->baseRisk($item, $catalog, $category);

        if ($catalog !== null) {
            $risk = $this->applyEscalationRules($risk, (array) ($catalog->escalation_rules ?? []), (array) ($item['extra_values'] ?? []));
        }

        $explicitSla = $catalog?->sla_hours ?? $category?->default_sla_hours;

        return [
            'risk_level_id' => $risk?->id,
            'responsible_department_id' => $this->intOrNull(
                $catalog?->responsible_department_id
                    ?? $category?->default_responsible_department_id
                    ?? ($item['responsible_department_id'] ?? null)
            ),
            'cc_department_ids' => $this->firstNonEmpty(
                $catalog?->cc_department_ids,
                $category?->default_cc_department_ids,
                $item['cc_department_ids'] ?? null,
            ),
            'sla_hours' => $this->intOrNull($explicitSla ?? $risk?->default_sla_hours),
            'kind' => $catalog?->kind ?? $category?->default_kind ?? ($item['kind'] ?? 'issue'),
        ];
    }

    private function baseRisk(array $item, ?ObservationCatalogItem $catalog, ?ObservationCategory $category): ?RiskLevel
    {
        $id = $catalog?->risk_level_id ?? $category?->default_risk_level_id;
        if ($id !== null) {
            return RiskLevel::find($id);
        }

        $slug = $item['risk_level_slug'] ?? null;

        return $slug ? RiskLevel::bySlug((string) $slug) : null;
    }

    /**
     * Rules are `{field, gte|lte|eq, risk}` (risk = slug); a single rule object is accepted too.
     * When several rules match, the most severe resulting risk wins.
     */
    private function applyEscalationRules(?RiskLevel $current, array $rules, array $values): ?RiskLevel
    {
        if ($rules !== [] && ! array_is_list($rules)) {
            $rules = [$rules];
        }

        $best = null;
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! $this->ruleMatches($rule, $values)) {
                continue;
            }
            $target = RiskLevel::bySlug((string) ($rule['risk'] ?? ''));
            if ($target !== null && ($best === null || $target->sort_order < $best->sort_order)) {
                $best = $target;
            }
        }

        return $best ?? $current;
    }

    private function ruleMatches(array $rule, array $values): bool
    {
        $field = $rule['field'] ?? null;
        if ($field === null || ! array_key_exists($field, $values) || ! is_numeric($values[$field])) {
            return false;
        }
        $value = (float) $values[$field];

        foreach (['gte', 'lte', 'eq'] as $op) {
            if (array_key_exists($op, $rule) && is_numeric($rule[$op])) {
                $limit = (float) $rule[$op];

                return match ($op) {
                    'gte' => $value >= $limit,
                    'lte' => $value <= $limit,
                    'eq' => $value == $limit,
                };
            }
        }

        return false;
    }

    private function firstNonEmpty(mixed ...$candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return array_values(array_map('intval', $candidate));
            }
        }

        return [];
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
