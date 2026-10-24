<?php

namespace App\Http\Requests\Observations\Web;

use Illuminate\Foundation\Http\FormRequest;

/** POST /observations/{observation}/reroute (web). Policy `reroute`: super_admin / ops_manager. */
class RerouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reroute', $this->route('observation'));
    }

    public function rules(): array
    {
        return [
            'responsible_department_id' => ['required', 'integer', 'exists:departments,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
