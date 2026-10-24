<?php

namespace App\Services\Observations\Import;

use App\Models\Department;

/** Workbook department labels -> reporting department (spec section 13). */
class DepartmentMapper
{
    public const SLUGS = [
        'cctv' => 'cctv',
        'stock control' => 'stock_control',
        'maintenance' => 'maintenance',
        'merchandising' => 'merchandise',
        'merchandise' => 'merchandise',
        'finance' => 'finance',
        'it' => 'it',
        'hr' => 'hr',
        'loss prevention' => 'lp',
        'lp' => 'lp',
    ];

    /** @param  array<string, int>  $slugToId */
    public function __construct(private array $slugToId) {}

    public static function fromDatabase(): self
    {
        return new self(Department::pluck('id', 'slug')->all());
    }

    public function toSlug(string $raw): string
    {
        $key = BranchMapper::normalise($raw);
        if (! isset(self::SLUGS[$key])) {
            throw new UnknownDepartment(trim($raw));
        }

        return self::SLUGS[$key];
    }

    /** Id of a department by its slug (not a workbook label); null when the department is missing. */
    public function idForSlug(string $slug): ?int
    {
        return isset($this->slugToId[$slug]) ? (int) $this->slugToId[$slug] : null;
    }

    public function toDepartmentId(string $raw): int
    {
        $slug = $this->toSlug($raw);
        if (! isset($this->slugToId[$slug])) {
            throw new UnknownDepartment(trim($raw)." (department [{$slug}] missing in the database)");
        }

        return (int) $this->slugToId[$slug];
    }
}
