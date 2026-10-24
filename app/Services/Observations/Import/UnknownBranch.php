<?php

namespace App\Services\Observations\Import;

/** A branch name in the workbook that no alias maps to an existing branch. */
class UnknownBranch extends \RuntimeException
{
    public function __construct(public readonly string $rawName)
    {
        parent::__construct("Unknown branch: {$rawName}");
    }
}
