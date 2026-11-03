<?php

namespace App\Services\Cctv;

use Illuminate\Support\Carbon;

/**
 * Turns one row of the weekly CCTV action-plan sheet ("CCTV- RTL - HR") into the parts the register needs:
 * the real date, the system branch, the category slug, the catalog code and the minutes.
 * Pure (no database), so the rules are unit-tested on their own.
 */
class CctvSheetMapper
{
    /** Sheet store name => branches.branch_name. */
    public const BRANCHES = [
        'Abbas 1' => 'Nasr City - Abbas El Akkad',
        'Abbas 2' => 'Nasr City - Abbas El Akkad / Ezzat Salama',
        'City Stars' => 'Nasr City - City Stars Mall',
        'Genena Mall' => 'Nasr City - Gnena Mall',
        'Nozha' => 'Nasr City - Nozha Street',
        'Hegaz' => 'Heliopolis - El Hegaz',
        'Merghany' => 'Heliopolis - El Marghany',
        'Galleria Mall' => '5th Settlement - Galleria Mall',
        'Point 90' => '5th Settlement - Point 90 Mall',
        'RBT' => '5th Settlement - El Rebat Mall',
        'The Yard' => 'El Rehab - The Yard Mall',
        'Madinaty' => 'Madinaty - Open Air Mall',
        'Mokkatam 1' => 'El Mokattam - Mokattam 1',
        'Mokkatam 2' => 'El Mokattam - Mokattam 2',
        'Maadi 1' => 'Maadi - Maadi 1',
        'Maadi 2' => 'Maadi - Maadi 2',
        'Mohandessin' => 'El Mohandessin - Mohandessin Store',
        'Mall of egypt' => '6th of October - Mall of Egypt Store',
        'Mall of arabia' => '6th of October - Mall of Arabia',
        'Zayed' => 'Sheikh Zayed - Saraya Mall',
        'Smouha' => 'Alexandria - Smouha',
        'Mansoura' => 'Mansoura - Mansoura Store',
        'Zagazig' => 'Zagazig - Zagazig Store 1',
        'Gamal abd el nasser' => 'Gamal Abd El Nasser Store',
    ];

    /** Sheet "نوع المخالفة" => observation_categories.slug of the cctv department. */
    public const CATEGORIES = [
        'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع' => 'branch_opening',
        'مخالفات تتعلق بمنطقة الفلور' => 'floor',
        'مخالفات تتعلق بمنطقة الكاشير' => 'cashier',
        'مخالفات تتعلق بمنطقة الاستوك' => 'stockroom',
        'مخالفات تتعلق بمنطقة الفيتنج رووم' => 'fitting_room',
        'مخالفات تتعلق بالكاميرات والديفي أر' => 'cameras_dvr',
    ];

    /** Pattern on the normalised violation text => catalog code; first match wins, so order matters. */
    public const RULES = [
        '/غلق.*بالمفتاح|عدم سماح.*بدخول الفرع/u' => 'CCTV-OPEN-LOCKED',
        '/تاخير.*فتح الفرع/u' => 'CCTV-OPEN-LATE',
        '/نيم تاج/u' => 'CCTV-FLOOR-NO-NAMETAG',
        '/اسكارف/u' => 'CCTV-FLOOR-NO-SCARF',
        '/سلسله|كروكس/u' => 'CCTV-FLOOR-ACCESSORY',
        '/تغير.*يونيفورم قبل/u' => 'CCTV-FLOOR-UNIFORM-EARLY',
        '/ارتداء.*اليونيفورم/u' => 'CCTV-FLOOR-NO-UNIFORM',
        '/موبايل الشخصي/u' => 'CCTV-FLOOR-MOBILE',
        '/بريك/u' => 'CCTV-FLOOR-BREAK',
        '/شيلفات/u' => 'CCTV-FLOOR-SHELVES',
        '/هزار|تيك توك|ماتش/u' => 'CCTV-FLOOR-CONDUCT',
        '/هندله|غلق نور الفرع|غلق جهاز الكمبيوتر/u' => 'CCTV-FLOOR-CLOSING',
        '/خروج الموظف \d+ دقيقه|ترك الفرع والجلوس|ترك موظفه جديده/u' => 'CCTV-FLOOR-LEFT-POST',
        '/اسلوب غير لائق/u' => 'CCTV-FLOOR-CUSTOMER-CONDUCT',
        '/عد.*(النقديه|الفلوس) بمفرده/u' => 'CCTV-CASH-COUNT-ALONE',
        '/الشنطه.*بعد غلق نور/u' => 'CCTV-CASH-BAG-LIGHTS-OFF',
        '/عدم فتح.*شنطه/u' => 'CCTV-CASH-BAG-OFF-CAMERA',
        '/تقفيل|التجميد|فلوس العهده/u' => 'CCTV-CASH-SAFE',
        '/فلوس من درج الكاش/u' => 'CCTV-CASH-DRAWER-NO-SLIP',
        '/بيانات العملاء/u' => 'CCTV-CASH-CUSTOMER-DATA',
        '/ماكولات|كشري/u' => 'CCTV-FOOD',
        '/الاستوك.*(نصف|4 ساعات)|جلوس.*الاستوك/u' => 'CCTV-STOCK-TEAM-INSIDE',
        '/اليوتيوب/u' => 'CCTV-STOCK-LAPTOP-MISUSE',
        '/اكثر من 5 قطع/u' => 'CCTV-FIT-OVER-5',
        '/بالشنطه|بشنطه/u' => 'CCTV-FIT-BAG',
        '/الديفي ار|فصل الكاميرات/u' => 'CCTV-DVR-DISCONNECTED',
    ];

    /** Codes whose minutes go in extra_values, with the key their catalog item uses. */
    public const MINUTE_KEYS = [
        'CCTV-OPEN-LATE' => 'minutes_late',
        'CCTV-FLOOR-BREAK' => 'minutes',
        'CCTV-FLOOR-UNIFORM-EARLY' => 'minutes',
    ];

    /**
     * Excel serials in these sheets were typed day-first and read month-first (1 Oct became 10 Jan), so the
     * day and month of a serial are swapped back. Text dates are dd-mm-yyyy or dd/mm/yyyy.
     */
    public static function date(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if (preg_match('/^\d{5}(\.0+)?$/', $raw)) {
            $d = Carbon::create(1899, 12, 30)->addDays((int) $raw);

            return checkdate($d->day, $d->month, $d->year) ? Carbon::create($d->year, $d->day, $d->month)->startOfDay() : null;
        }
        if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $raw, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        }

        return null;
    }

    public static function normalize(string $text): string
    {
        $text = str_replace(['إ', 'أ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $text);
        $text = preg_replace('/(\d+)/u', ' $1 ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function code(string $text): ?string
    {
        $t = self::normalize($text);
        foreach (self::RULES as $pattern => $code) {
            if (preg_match($pattern, $t)) {
                return $code;
            }
        }

        return null;
    }

    public static function minutes(string $text): ?int
    {
        $t = self::normalize($text);
        if (str_contains($t, 'ساعتين')) {
            return 120;
        }
        if (preg_match('/(\d+) ?(دقيقه|دقائق)/u', $t, $m)) {
            return (int) $m[1];
        }
        if (str_contains($t, 'ساعه')) {
            return 60;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row  header => cell of one sheet row
     * @return array{employee: string, store: string, branch_name: ?string, date: ?Carbon, category_slug: ?string, code: ?string, extra_values: array<string, mixed>, text: string}
     */
    public static function map(array $row): array
    {
        $text = trim($row['المخالفات'] ?? '');
        $code = self::code($text);
        $store = trim($row['الفرع'] ?? '');
        $extra = [];
        if ($code !== null && isset(self::MINUTE_KEYS[$code]) && ($minutes = self::minutes($text)) !== null) {
            $extra[self::MINUTE_KEYS[$code]] = $minutes;
        }
        $reply = trim($row['ملاحظات'] ?? '');
        if ($reply !== '') {
            $extra['reply'] = $reply;
        }
        $employee = trim($row['اسم الموظف'] ?? '');

        return [
            'employee' => in_array($employee, ['__', '-'], true) ? '' : $employee,
            'store' => $store,
            'branch_name' => self::BRANCHES[$store] ?? null,
            'date' => self::date($row['تاريخ المخالفة'] ?? ''),
            'category_slug' => self::CATEGORIES[trim($row['نوع المخالفة'] ?? $row['المخالفة'] ?? '')] ?? null,
            'code' => $code,
            'extra_values' => $extra,
            'text' => $text,
        ];
    }

    /** Same violation, same person, same day: the weekly sheets repeat a few rows of the week before. */
    public static function dedupeKey(array $mapped): string
    {
        return implode('|', [
            \App\Models\Employee::normalizeName($mapped['employee']), $mapped['store'], $mapped['date']?->toDateString(),
            $mapped['code'], mb_substr(self::normalize($mapped['text']), 0, 40),
        ]);
    }
}
