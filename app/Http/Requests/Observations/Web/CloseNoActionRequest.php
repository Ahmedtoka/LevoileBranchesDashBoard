<?php

namespace App\Http\Requests\Observations\Web;

use Illuminate\Foundation\Http\FormRequest;

/** POST /observations/{observation}/close-no-action (web). Policy `closeNoAction`: super_admin / ops_manager. */
class CloseNoActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('closeNoAction', $this->route('observation'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
