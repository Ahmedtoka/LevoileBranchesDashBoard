<?php

namespace App\Services\Observations\Import;

class UnknownDepartment extends \RuntimeException
{
    public function __construct(public readonly string $rawName)
    {
        parent::__construct("Unknown department: {$rawName}");
    }
}
