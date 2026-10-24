<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/observation-visits/{visit}/submit. No body; it exists so every POST of the module has a
 * FormRequest. Authorization stays in the controller (policy `submit`).
 */
class SubmitObservationVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
