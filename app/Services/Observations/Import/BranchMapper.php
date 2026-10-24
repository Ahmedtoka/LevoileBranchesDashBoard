<?php

namespace App\Services\Observations\Import;

use App\Models\Branch;

/**
 * Raw branch spellings of the October 2026 workbook -> branch id (spec section 13 alias table plus the
 * owner's three extra branches). The target names are matched against `branches.branch_name` either in
 * full or by the part after " - " (e.g. "City Stars Mall" -> "Nasr City - City Stars Mall").
 */
class BranchMapper
{
    public const ALL = 'ALL';

    /** Normalised raw spelling => canonical branch (short) name; `ALL` = every branch. */
    private const ALIASES = [
        'all branches' => self::ALL, 'all' => self::ALL, 'كل الفروع' => self::ALL, 'جميع الفروع' => self::ALL,

        'city stars' => 'City Stars Mall', 'city stars mall' => 'City Stars Mall', 'سيتي ستارز' => 'City Stars Mall',
        'mall of egypt' => 'Mall of Egypt Store', 'mall of egypt store' => 'Mall of Egypt Store', 'moe' => 'Mall of Egypt Store', 'مول مصر' => 'Mall of Egypt Store',
        'moe 2' => 'Mall of Egypt 2', 'moe2' => 'Mall of Egypt 2', 'mall of egypt 2' => 'Mall of Egypt 2', 'مول مصر 2' => 'Mall of Egypt 2',
        'mall of arabia' => 'Mall of Arabia', 'moa' => 'Mall of Arabia', 'مول العرب' => 'Mall of Arabia',
        'zayed' => 'Saraya Mall', 'sheikh zayed' => 'Saraya Mall', 'saraya' => 'Saraya Mall', 'saraya mall' => 'Saraya Mall', 'زايد' => 'Saraya Mall',
        'hegaz' => 'El Hegaz', 'el hegaz' => 'El Hegaz', 'الحجاز' => 'El Hegaz',
        'marghany' => 'El Marghany', 'merghany' => 'El Marghany', 'el marghany' => 'El Marghany', 'الميرغني' => 'El Marghany', 'الميرغنى' => 'El Marghany',
        'nozha' => 'Nozha Street', 'elnozha' => 'Nozha Street', 'el nozha' => 'Nozha Street', 'nozha street' => 'Nozha Street', 'النزهة' => 'Nozha Street', 'النزهه' => 'Nozha Street',
        'abbas 1' => 'Abbas El Akkad', 'abbas1' => 'Abbas El Akkad', 'عباس 1' => 'Abbas El Akkad', 'عباس1' => 'Abbas El Akkad',
        'abbas 2' => 'Abbas El Akkad / Ezzat Salama', 'abbas2' => 'Abbas El Akkad / Ezzat Salama', 'عباس 2' => 'Abbas El Akkad / Ezzat Salama', 'عباس2' => 'Abbas El Akkad / Ezzat Salama',
        'genena mall' => 'Gnena Mall', 'genena' => 'Gnena Mall', 'gnena mall' => 'Gnena Mall', 'جنينة' => 'Gnena Mall', 'جنينه' => 'Gnena Mall', 'جنينه مول' => 'Gnena Mall', 'جنينة مول' => 'Gnena Mall',
        'rbt' => 'El Rebat Mall', 'rebat' => 'El Rebat Mall', 'el rebat' => 'El Rebat Mall', 'el rebat mall' => 'El Rebat Mall',
        'the yard' => 'The Yard Mall', 'theyard' => 'The Yard Mall', 'yard' => 'The Yard Mall', 'the yard mall' => 'The Yard Mall',
        'point 90' => 'Point 90 Mall', 'point90' => 'Point 90 Mall', 'point 90 mall' => 'Point 90 Mall',
        'madinaty' => 'Open Air Mall', 'open air' => 'Open Air Mall', 'open air mall' => 'Open Air Mall', 'مدينتي' => 'Open Air Mall',
        'maadi 1' => 'Maadi 1', 'maadi1' => 'Maadi 1', 'المعادي 1' => 'Maadi 1',
        'maadi 2' => 'Maadi 2', 'maadi2' => 'Maadi 2', 'المعادي 2' => 'Maadi 2',
        'mokattam 1' => 'Mokattam 1', 'mokkatam 1' => 'Mokattam 1', 'mokkatm 1' => 'Mokattam 1', 'المقطم 1' => 'Mokattam 1',
        'mokattam 2' => 'Mokattam 2', 'mokkatam 2' => 'Mokattam 2', 'mokkatm 2' => 'Mokattam 2', 'المقطم 2' => 'Mokattam 2',
        'smouha' => 'Smouha', 'سموحة' => 'Smouha', 'سموحه' => 'Smouha',
        'mansoura' => 'Mansoura Store', 'mansoura store' => 'Mansoura Store', 'المنصورة' => 'Mansoura Store',
        'zagazig' => 'Zagazig Store 1', 'zagazig store 1' => 'Zagazig Store 1', 'الزقازيق' => 'Zagazig Store 1',
        'mohandessin' => 'Mohandessin Store', 'mohandessin store' => 'Mohandessin Store', 'المهندسين' => 'Mohandessin Store',
        'galleria' => 'Galleria Mall', 'galleria mall' => 'Galleria Mall',
        'san stefano' => 'San Stefano Mall', 'san stefano mall' => 'San Stefano Mall',
        'miami' => 'Miami', 'ekbal' => 'El Ekbal', 'el ekbal' => 'El Ekbal',

        // Owner decision 2026-10-24: three branches not in the system are created by the import command.
        'shehab' => 'Shehab Store', 'shehab store' => 'Shehab Store', 'شهاب' => 'Shehab Store',
        'gamal abd el nasser' => 'Gamal Abd El Nasser Store', 'gamal abd el nasser store' => 'Gamal Abd El Nasser Store', 'جمال عبد الناصر' => 'Gamal Abd El Nasser Store',
        'mds' => 'MDS Store', 'mds store' => 'MDS Store',
    ];

    /** @var array<string, int> normalised full or short branch name => id */
    private array $ids = [];

    /** @param  array<string, int>  $branchNameToId  `branch_name` => id */
    public function __construct(array $branchNameToId)
    {
        foreach ($branchNameToId as $name => $id) {
            $this->ids[self::normalise($name)] = (int) $id;
            if (str_contains($name, ' - ')) {
                $this->ids[self::normalise(substr($name, strpos($name, ' - ') + 3))] = (int) $id;
            }
        }
    }

    /**
     * Every branch in the database plus `$extra` (`branch_name` => id), used by the dry run for branches
     * the real run will create.
     */
    public static function fromDatabase(array $extra = []): self
    {
        return new self(array_merge(Branch::pluck('id', 'branch_name')->all(), $extra));
    }

    /**
     * @return int|string the branch id, or `self::ALL`
     *
     * @throws UnknownBranch when the spelling has no alias or its target branch is missing
     */
    public function toBranchId(string $raw): int|string
    {
        $key = self::normalise($raw);
        $canonical = self::ALIASES[$key] ?? null;

        if ($canonical === self::ALL) {
            return self::ALL;
        }

        $id = $this->ids[self::normalise($canonical ?? $raw)] ?? null;
        if ($id === null) {
            throw new UnknownBranch(trim($raw));
        }

        return $id;
    }

    public static function canonicalName(string $raw): ?string
    {
        return self::ALIASES[self::normalise($raw)] ?? null;
    }

    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value) ?? $value; // tashkeel, tatweel

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
