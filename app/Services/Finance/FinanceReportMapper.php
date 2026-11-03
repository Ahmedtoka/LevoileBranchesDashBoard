<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;

/**
 * Reads the weekly finance report ("التقرير الأسبوعي للإدارة المالية", a Word file) and maps its parts to
 * the register: the period, the branch-by-control matrix and the list of problems. Pure apart from reading
 * the file, so the rules are tested on their own.
 */
class FinanceReportMapper
{
    /** Report branch names (Arabic in the matrix, English in the problem list) => branches.branch_name. */
    public const BRANCHES = [
        'عباس1' => 'Nasr City - Abbas El Akkad', 'abbas1' => 'Nasr City - Abbas El Akkad',
        'عباس2' => 'Nasr City - Abbas El Akkad / Ezzat Salama', 'abbas2' => 'Nasr City - Abbas El Akkad / Ezzat Salama',
        'سيتيستار' => 'Nasr City - City Stars Mall', 'سيتيستارز' => 'Nasr City - City Stars Mall', 'citystars' => 'Nasr City - City Stars Mall',
        'زايد' => 'Sheikh Zayed - Saraya Mall', 'zayed' => 'Sheikh Zayed - Saraya Mall',
        'الرباط' => '5th Settlement - El Rebat Mall', 'rbt' => '5th Settlement - El Rebat Mall',
        'يارد' => 'El Rehab - The Yard Mall', 'الرحاب' => 'El Rehab - The Yard Mall', 'theyard' => 'El Rehab - The Yard Mall',
        // The first report wrote "المقطم"; the next ones "المقطم 2" / "Mokkatm 2" (owner's decision, 2026-11-03).
        'المقطم' => 'El Mokattam - Mokattam 2', 'المقطم2' => 'El Mokattam - Mokattam 2', 'mokkatm2' => 'El Mokattam - Mokattam 2', 'mokattam2' => 'El Mokattam - Mokattam 2',
        'المرغني' => 'Heliopolis - El Marghany', 'الميرغني' => 'Heliopolis - El Marghany', 'mergany' => 'Heliopolis - El Marghany', 'merghany' => 'Heliopolis - El Marghany',
        'جنينهمول' => 'Nasr City - Gnena Mall', 'genenamall' => 'Nasr City - Gnena Mall',
        'المعادي' => 'Maadi - Maadi 1', 'maadi1' => 'Maadi - Maadi 1',
        'المعادي2' => 'Maadi - Maadi 2', 'maadi2' => 'Maadi - Maadi 2',
        'سموحه' => 'Alexandria - Smouha', 'smouha' => 'Alexandria - Smouha',
        'المنصوره' => 'Mansoura - Mansoura Store', 'mansoura' => 'Mansoura - Mansoura Store',
        'الحجاز' => 'Heliopolis - El Hegaz', 'hegaz' => 'Heliopolis - El Hegaz',
        'النزهه' => 'Nasr City - Nozha Street', 'nozha' => 'Nasr City - Nozha Street',
        'مولالعرب' => '6th of October - Mall of Arabia', 'mallofarabia' => '6th of October - Mall of Arabia', 'moa' => '6th of October - Mall of Arabia',
        // "مول مصر" in the matrix is the branch the problem list calls "MOE 2" (owner's decision, 2026-11-03).
        'مولمصر' => '6th of October - Mall of Egypt 2', 'moe2' => '6th of October - Mall of Egypt 2',
        'جاليريا' => '5th Settlement - Galleria Mall', 'galleria' => '5th Settlement - Galleria Mall',
        'بوينت90' => '5th Settlement - Point 90 Mall', 'point90' => '5th Settlement - Point 90 Mall',
        'mds' => 'MDS Store',
    ];

    /**
     * The branches each report covers: it counts 26 and names only those with problems, so the others were
     * reviewed and clean. 26 = the active branches without the three small stores and the Mall of Egypt
     * store, which the finance reports do not follow (assumption, 2026-11-03).
     */
    public const NOT_FOLLOWED = ['Shehab Store', 'Gamal Abd El Nasser Store', 'MDS Store', '6th of October - Mall of Egypt Store'];

    /** Matrix column header (normalised) => category slug; checked in this order. */
    public const COLUMNS = [
        'مرتجعات' => 'returns', 'العهده' => 'float', 'عجز' => 'cash_variance', 'التجميدات' => 'freezes',
        // "مستندات يوميه / ايصالات مصاريف" must be tested before the expenses column ("التوجيهات للمصاريف").
        'اليوميات' => 'journals', 'مستنداتيوميه' => 'documents', 'مصاريف' => 'expenses', 'الايميلات' => 'emails',
    ];

    /** The catalog item a bare tick stands for, per category. */
    public const DEFAULT_ITEM = [
        'returns' => 'FIN-RETURNS-NO-INVOICE', 'float' => 'FIN-FLOAT-UNSETTLED', 'cash_variance' => 'FIN-CASH-SHORTAGE',
        'freezes' => 'FIN-FREEZE-ISSUE', 'journals' => 'FIN-JOURNALS-NEGLECTED', 'expenses' => 'FIN-EXPENSE-INSTRUCTIONS',
        'emails' => 'FIN-EMAILS-NOT-SENT', 'documents' => 'FIN-DOCS-MISSING',
    ];

    /** Problem text (normalised) => [catalog code, category slug]; first match wins. */
    public const RULES = [
        '/بعد 14|بعد ١٤/u' => ['FIN-RETURN-LATE', 'returns'],
        '/مرتجع مفقود/u' => ['FIN-RETURN-LOST', 'returns'],
        '/سداد مبلغ شخصي/u' => ['FIN-CASH-PERSONAL-PAYMENT', 'expenses'],
        '/اكسكلو/u' => ['FIN-EXCLUSIVE-VOUCHER', 'returns'],
        '/مرتجع/u' => ['FIN-RETURNS-NO-INVOICE', 'returns'],
        '/عجز/u' => ['FIN-CASH-SHORTAGE', 'cash_variance'],
        '/زياده/u' => ['FIN-CASH-OVERAGE', 'cash_variance'],
        '/تجميد.*مختلف/u' => ['FIN-FREEZE-MISMATCH', 'freezes'],
        '/تجميد/u' => ['FIN-FREEZE-ISSUE', 'freezes'],
        '/z ?report/iu' => ['FIN-ZREPORT', 'journals'],
        '/سريال/u' => ['FIN-JOURNAL-SERIAL-SKIP', 'journals'],
        '/الفيزا/u' => ['FIN-VISA-CLOSE', 'journals'],
        '/ايصالات مصاريف/u' => ['FIN-EXPENSE-RECEIPTS-MISSING', 'documents'],
        '/مستندات ناقص/u' => ['FIN-DOCS-MISSING', 'documents'],
        '/المصروفات|المصاريف/u' => ['FIN-EXPENSE-INSTRUCTIONS', 'expenses'],
        '/ايميل|ميل/u' => ['FIN-EMAILS-NOT-SENT', 'emails'],
    ];

    public static function normalize(string $text): string
    {
        $text = str_replace(['إ', 'أ', 'آ', 'ة', 'ى', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], ['ا', 'ا', 'ا', 'ه', 'ي', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Key for BRANCHES: normalised, lower-case, no spaces. */
    public static function branchKey(string $name): string
    {
        return mb_strtolower(str_replace(' ', '', self::normalize($name)));
    }

    public static function branch(string $name): ?string
    {
        return self::BRANCHES[self::branchKey($name)] ?? null;
    }

    public static function column(string $header): ?string
    {
        $h = str_replace(' ', '', self::normalize($header));
        foreach (self::COLUMNS as $needle => $slug) {
            if (str_contains($h, $needle)) {
                return $slug;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null [catalog code, category slug] */
    public static function problem(string $text): ?array
    {
        $t = self::normalize($text);
        foreach (self::RULES as $pattern => $hit) {
            if (preg_match($pattern, $t)) {
                return $hit;
            }
        }

        return null;
    }

    public static function amount(string $text): ?int
    {
        $t = self::normalize($text);

        return preg_match('/(\d+)\s*ج|بقيم[هة]?\s*(\d+)/u', $t, $m) ? (int) ($m[1] !== '' ? $m[1] : $m[2]) : null;
    }

    /** "من: 19 /.10 /.2026 إلى: 24/.10/.2026" => [from|null, to|null] */
    public static function period(string $text): array
    {
        preg_match_all('#(\d{1,2})\s*/\s*\.?\s*(\d{1,2})\s*/\s*\.?\s*(\d{4})#u', self::normalize($text), $m, PREG_SET_ORDER);
        $dates = array_map(fn ($d) => Carbon::create((int) $d[3], (int) $d[2], (int) $d[1])->startOfDay(), $m);

        return count($dates) >= 2 ? [$dates[0], $dates[1]] : [null, $dates[0] ?? null];
    }

    /**
     * @return array{from: ?Carbon, to: ?Carbon, matrix: list<array{branch: string, columns: list<string>}>, problems: list<array{branch: string, text: string, action: string}>, notes: list<array{branch: string, text: string, action: string}>}
     */
    public static function read(string $path): array
    {
        $tables = self::tables($path);
        $out = ['from' => null, 'to' => null, 'matrix' => [], 'problems' => [], 'notes' => []];

        foreach ($tables as $rows) {
            $head = $rows[0] ?? [];
            if (($head[0] ?? '') === 'رقم الأسبوع') {
                [$out['from'], $out['to']] = self::period($head[3] ?? '');
            } elseif (($head[0] ?? '') === 'الفرع' && count($head) > 4) {
                $columns = array_map(fn ($h) => self::column($h), $head);
                foreach (array_slice($rows, 1) as $r) {
                    if (trim($r[0] ?? '') === '') {
                        continue;
                    }
                    $hits = [];
                    foreach ($r as $i => $cell) {
                        if ($i > 0 && $columns[$i] !== null && str_contains($cell, '✓')) {
                            $hits[] = $columns[$i];
                        }
                    }
                    $out['matrix'][] = ['branch' => trim($r[0]), 'columns' => $hits];
                }
            } elseif (($head[0] ?? '') === 'م') {
                // The first report's table summed each branch up ("الأثر" column): a note on the branch, not one problem.
                $kind = in_array('الأثر', $head, true) ? 'notes' : 'problems';
                $actionAt = count($head) - 1;
                foreach (array_slice($rows, 1) as $r) {
                    if (trim($r[1] ?? '') === '' || trim($r[2] ?? '') === '') {
                        continue;
                    }
                    $out[$kind][] = ['branch' => trim($r[1]), 'text' => trim($r[2]), 'action' => trim($r[$actionAt] ?? '')];
                }
            }
        }

        return $out;
    }

    /** Every table of the document as rows of cell texts. */
    private static function tables(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Cannot open {$path}");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $doc = new \DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = [];
        foreach ($xp->query('//w:body/w:tbl') as $tbl) {
            $rows = [];
            foreach ($xp->query('./w:tr', $tbl) as $tr) {
                $cells = [];
                foreach ($xp->query('./w:tc', $tr) as $tc) {
                    $text = '';
                    foreach ($xp->query('.//w:t', $tc) as $t) {
                        $text .= $t->textContent;
                    }
                    $cells[] = trim($text);
                }
                $rows[] = $cells;
            }
            $tables[] = $rows;
        }

        return $tables;
    }
}
