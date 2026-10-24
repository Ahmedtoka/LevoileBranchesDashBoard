<?php

namespace App\Http\Requests\Tickets;

use App\Services\TicketService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/tickets/{ticket}/reply and the web ticket page (Plan H): the responsible department answers
 * with "جاري العمل عليها" (working) or "تم الحل" (solved) and a mandatory note. Authorization in the
 * controller (TicketService::canReply).
 */
class ReplyTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(TicketService::REPLY_STATES)],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => dt('اكتب ملاحظة مع الرد.', 'A note is required with the reply.'),
            'note.min' => dt('الملاحظة قصيرة جداً.', 'The note is too short.'),
        ];
    }
}
