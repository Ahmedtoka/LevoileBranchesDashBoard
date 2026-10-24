<?php

namespace App\Http\Requests\Observations;

use App\Models\ObservationVerification;
use App\Rules\OwnObservationPhotoPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/observations/{observation}/verify. Authorization stays in the controller (policy `verify`);
 * the service enforces "notes required for not_resolved".
 */
class VerifyObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(ObservationVerification::RESULTS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo_path' => ['nullable', 'string', 'max:255', new OwnObservationPhotoPath($this->user()?->id)],
            'observation_visit_id' => ['nullable', 'integer', 'exists:observation_visits,id'],
        ];
    }
}
