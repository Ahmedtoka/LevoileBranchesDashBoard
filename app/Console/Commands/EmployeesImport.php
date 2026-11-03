<?php

namespace App\Console\Commands;

use App\Services\Hr\EmployeesImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Loads the HR staff export from the command line (the HR page does the same by upload); see EmployeesImporter.
 *
 *   php artisan employees:import "C:\path\users.xlsx" --dry-run
 */
class EmployeesImport extends Command
{
    protected $signature = 'employees:import {path} {--dry-run : Show what would change, write nothing}';

    protected $description = 'Import the HR staff export (code, name, manager, section, department, place of work).';

    public function handle(EmployeesImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $plan = $importer->plan($path);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $people = collect($plan['people']);
        $this->info(sprintf('%d employees in the file: %d at a branch, %d elsewhere.', $people->count(),
            $people->whereNotNull('branch_id')->count(), $people->whereNull('branch_id')->count()));
        $this->line(sprintf('New: %d · changed: %d · CCTV names matched: %d (+%d other spellings merged) · not matched: %d · no longer in the file (made inactive): %d',
            count($plan['new']), count($plan['changed']), count($plan['links']), count($plan['merges']), count($plan['ambiguous']), count($plan['gone'])));
        $this->line('Places with no branch: '.collect($plan['places'])->map(fn ($n, $p) => "{$p} ({$n})")->implode(', '));
        if ($plan['ambiguous'] !== []) {
            $this->table(['id', 'CCTV name', 'branch', 'candidates'], $plan['ambiguous']);
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $importer->apply($plan);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
