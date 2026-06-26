<?php

use App\Models\Translation;

if (! function_exists('dt')) {
    /** Quick inline bilingual string: dt('عربي', 'English'). */
    function dt(string $ar, string $en): string
    {
        return app()->getLocale() === 'en' ? $en : $ar;
    }
}

if (! function_exists('t')) {
    /** Central, editable translation: t('nav.tickets', 'التذاكر') backed by the translations table. */
    function t(string $key, ?string $fallback = null): string
    {
        static $map;
        if (! isset($map)) {
            try {
                $map = Translation::all()->keyBy('key');
            } catch (\Throwable $e) {
                $map = collect();
            }
        }
        $row = $map->get($key);
        if (! $row) {
            return $fallback ?? $key;
        }
        $val = app()->getLocale() === 'en' ? ($row->en ?: $row->ar) : ($row->ar ?: $row->en);

        return $val ?: ($fallback ?? $key);
    }
}
