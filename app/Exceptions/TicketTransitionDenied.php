<?php

namespace App\Exceptions;

/** A ticket status change refused by the service; carries the HTTP status (403 or 422) for the caller. */
class TicketTransitionDenied extends \RuntimeException
{
    public readonly int $status;

    public function __construct(string $messageAr, string $messageEn, int $status)
    {
        parent::__construct(dt($messageAr, $messageEn));
        $this->status = $status;
    }

    public static function notAllowed(): self
    {
        return new self('غير مسموح لك بإقفال هذا الطلب.', 'You are not allowed to close this ticket.', 403);
    }

    public static function afterEvidenceRequired(): self
    {
        return new self('لا يمكن الإقفال بدون صورة بعد الإصلاح.', 'Cannot close without an after photo.', 422);
    }

    public static function invalidTransition(): self
    {
        return new self('انتقال غير مسموح في دورة الحالة.', 'Transition not allowed in the status cycle.', 422);
    }
}
