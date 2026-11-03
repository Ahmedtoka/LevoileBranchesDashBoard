<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the constitution page, the simulator and their evidence attachments to logged-in users only.
 * The pages live in docs/handover (deployed by git); the attachments hold employee names, stay out of git
 * and out of public/, and live in storage/app/handover-evidence.
 */
class ConstitutionController extends Controller
{
    private const PAGES = ['department-blueprint.standalone.html', 'department-simulator.html'];

    public function page(string $page): BinaryFileResponse
    {
        abort_unless(in_array($page, self::PAGES, true), 404);

        return $this->serve(base_path('docs/handover/'.$page));
    }

    public function evidence(string $file): BinaryFileResponse
    {
        return $this->serve(storage_path('app/handover-evidence/'.$file));
    }

    private function serve(string $path): BinaryFileResponse
    {
        abort_unless(is_file($path), 404);

        return response()->file($path, ['X-Robots-Tag' => 'noindex']);
    }
}
