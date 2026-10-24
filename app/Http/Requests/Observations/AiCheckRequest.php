<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/observation-visits/{visit}/ai-check. Authorization stays in the controller (policy `update`). */
class AiCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*.index' => ['required', 'integer', 'min:0', 'distinct'],
            'points.*.text' => ['required', 'string', 'max:2000'],
        ];
    }
}
