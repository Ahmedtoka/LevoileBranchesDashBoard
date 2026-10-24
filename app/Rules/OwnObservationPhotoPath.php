<?php

namespace App\Rules;

use App\Models\ObservationVisit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A photo path sent back by a client (upsert `photos.*`, verify `photo_path`) must be one the photos endpoint
 * can have returned to that caller: `evidence/observations/{visit}/{file}.(jpg|jpeg|png|webp)` under a visit
 * the caller owns. Pages build URLs from these paths, so an absolute URL or another user's file is refused.
 * The same path may be reused for the caller's other visits (the web page uploads once under the first one).
 */
class OwnObservationPhotoPath implements ValidationRule
{
    public const PATTERN = '#^evidence/observations/(\d+)/[^/]+\.(jpe?g|png|webp)$#i';

    /** @var array<int, bool> visit id => owned by the caller */
    private array $owned = [];

    public function __construct(private ?int $userId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value, $m) || str_contains($value, '..')) {
            $fail(dt('مسار الصورة غير صالح.', 'The photo path is not valid.'));

            return;
        }

        $visitId = (int) $m[1];
        $this->owned[$visitId] ??= $this->userId !== null
            && ObservationVisit::whereKey($visitId)->where('user_id', $this->userId)->exists();

        if (! $this->owned[$visitId]) {
            $fail(dt('الصورة لا تخص زياراتك.', 'The photo does not belong to one of your visits.'));
        }
    }
}
