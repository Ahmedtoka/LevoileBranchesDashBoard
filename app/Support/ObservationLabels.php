<?php

namespace App\Support;

/** Display labels / colours for observation statuses, shared by the pages and the CSV export. */
class ObservationLabels
{
    /** status => [Arabic, English, bootstrap colour] */
    private const STATUSES = [
        'open' => ['مفتوحة', 'Open', 'secondary'],
        'in_progress' => ['جاري التنفيذ', 'In progress', 'warning'],
        'waiting_verification' => ['بانتظار التحقق', 'Waiting verification', 'info'],
        'verified_closed' => ['مغلقة بعد التحقق', 'Verified & closed', 'success'],
        'reopened' => ['أُعيد فتحها', 'Reopened', 'danger'],
        'closed_no_action' => ['مغلقة بدون إجراء', 'Closed, no action', 'dark'],
        'positive' => ['إيجابية', 'Positive', 'success'],
    ];

    public static function status(string $status): string
    {
        $row = self::STATUSES[$status] ?? null;

        return $row ? dt($row[0], $row[1]) : $status;
    }

    public static function statusColor(string $status): string
    {
        return self::STATUSES[$status][2] ?? 'secondary';
    }

    public static function classification(string $status): string
    {
        return match ($status) {
            'catalog' => dt('من الكتالوج', 'From catalog'),
            'manual' => dt('يدوي', 'Manual'),
            'ai_pending_review' => dt('بانتظار اعتماد الفحص', 'Awaiting review'),
            'ai_reviewed' => dt('مُعتمد', 'Reviewed'),
            default => $status,
        };
    }

    public static function verificationResult(string $result): string
    {
        return match ($result) {
            'resolved' => dt('تم الحل', 'Resolved'),
            'not_resolved' => dt('لم يتم الحل', 'Not resolved'),
            'partially' => dt('حل جزئي', 'Partially resolved'),
            default => $result,
        };
    }

    public static function verificationColor(string $result): string
    {
        return match ($result) {
            'resolved' => 'success',
            'not_resolved' => 'danger',
            'partially' => 'warning',
            default => 'secondary',
        };
    }

    public static function visitType(string $type): string
    {
        return match ($type) {
            'field' => dt('ميدانية', 'Field'),
            'desk' => dt('مكتبية', 'Desk'),
            default => $type,
        };
    }

    public static function kind(string $kind): string
    {
        return match ($kind) {
            'issue' => dt('مشكلة', 'Issue'),
            'activity' => dt('نشاط', 'Activity'),
            'assessment' => dt('تقييم', 'Assessment'),
            'positive' => dt('إيجابية', 'Positive'),
            default => $kind,
        };
    }

    /**
     * Public URL of a photo / evidence path on the public disk, or null when the path is not a plain relative
     * storage path (an absolute URL, a protocol-relative or scheme-bearing value, or one that climbs out with `..`).
     * Observation photo paths come from clients, so the pages never echo an absolute value as a link or image.
     */
    public static function storageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, ':') || str_contains($path, '..')) {
            return null;
        }

        return asset('storage/'.$path);
    }
}
