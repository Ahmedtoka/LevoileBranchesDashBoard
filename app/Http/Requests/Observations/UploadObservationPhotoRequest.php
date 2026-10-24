<?php

namespace App\Http\Requests\Observations;

use App\Models\ObservationPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/observation-visits/{visit}/photos (multipart). Authorization stays in the controller (policy `update`). */
class UploadObservationPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'max:8192'],
            'kind' => ['nullable', Rule::in(ObservationPhoto::KINDS)],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.max' => 'حجم الصورة أكبر من 8 ميجا.',
        ];
    }
}
