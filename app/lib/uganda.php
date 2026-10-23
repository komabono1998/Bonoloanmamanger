<?php
/**
 * Uganda address data for drop-down entry (district > county > sub-county > parish > village) and a list of popular professions.
 * Districts and cities ship with the system; counties, sub-counties, parishes and villages are loaded with the importer
 * (Administration > Uganda Locations, or tools/import_uganda_locations.php) and then appear in the drop-downs automatically.
 */
class Uganda
{
    public const DISTRICTS = ['Abim', 'Adjumani', 'Agago', 'Alebtong', 'Amolatar', 'Amudat', 'Amuria', 'Amuru', 'Apac', 'Arua', 'Arua City', 'Budaka', 'Bududa', 'Bugiri', 'Bugweri', 'Buhweju', 'Buikwe', 'Bukedea',
        'Bukomansimbi', 'Bukwo', 'Bulambuli', 'Buliisa', 'Bundibugyo', 'Bunyangabu', 'Bushenyi', 'Busia', 'Butaleja', 'Butambala', 'Butebo', 'Buvuma', 'Buyende', 'Dokolo', 'Fort Portal City', 'Gomba', 'Gulu', 'Gulu City',
        'Hoima', 'Hoima City', 'Ibanda', 'Iganga', 'Isingiro', 'Jinja', 'Jinja City', 'Kaabong', 'Kabale', 'Kabarole', 'Kaberamaido', 'Kagadi', 'Kakumiro', 'Kalaki', 'Kalangala', 'Kaliro', 'Kalungu', 'Kampala',
        'Kamuli', 'Kamwenge', 'Kanungu', 'Kapchorwa', 'Kapelebyong', 'Karenga', 'Kasanda', 'Kasese', 'Katakwi', 'Kayunga', 'Kazo', 'Kibaale', 'Kiboga', 'Kibuku', 'Kikuube', 'Kiruhura', 'Kiryandongo', 'Kisoro',
        'Kitagwenda', 'Kitgum', 'Koboko', 'Kole', 'Kotido', 'Kumi', 'Kwania', 'Kween', 'Kyankwanzi', 'Kyegegwa', 'Kyenjojo', 'Kyotera', 'Lamwo', 'Lira', 'Lira City', 'Luuka', 'Luwero', 'Lwengo', 'Lyantonde',
        'Madi-Okollo', 'Manafwa', 'Maracha', 'Masaka', 'Masaka City', 'Masindi', 'Mayuge', 'Mbale', 'Mbale City', 'Mbarara', 'Mbarara City', 'Mitooma', 'Mityana', 'Moroto', 'Moyo', 'Mpigi', 'Mubende', 'Mukono',
        'Nabilatuk', 'Nakapiripirit', 'Nakaseke', 'Nakasongola', 'Namayingo', 'Namisindwa', 'Namutumba', 'Napak', 'Nebbi', 'Ngora', 'Ntoroko', 'Ntungamo', 'Nwoya', 'Obongi', 'Omoro', 'Otuke', 'Oyam', 'Pader',
        'Pakwach', 'Pallisa', 'Rakai', 'Rubanda', 'Rubirizi', 'Rukiga', 'Rukungiri', 'Rwampara', 'Sembabule', 'Serere', 'Sheema', 'Sironko', 'Soroti', 'Soroti City', 'Terego', 'Tororo', 'Wakiso', 'Yumbe', 'Zombo'];

    /** The most common occupations among borrowers, grouped for the drop-down. */
    public const PROFESSIONS = [
        'Trade & business' => ['Shop owner / retailer', 'Market vendor', 'Wholesaler', 'Boutique / clothing trader', 'Hardware shop owner', 'Mobile money agent', 'Street vendor / hawker', 'Salon / barber', 'Restaurant / food vendor', 'Bar / hotel owner', 'Tailor / fashion designer', 'Cross-border trader', 'Produce / grain trader', 'Charcoal / firewood seller'],
        'Agriculture' => ['Farmer (crops)', 'Livestock farmer', 'Poultry farmer', 'Dairy farmer', 'Fish farmer / fisherman', 'Coffee farmer', 'Banana / matooke farmer', 'Horticulture farmer', 'Beekeeper', 'Agro-input dealer', 'Veterinary service provider', 'Maize farmer', 'Rice farmer', 'Cotton farmer', 'Tea farmer', 'Sugarcane farmer', 'Gardener / landscaper'],
        'Transport' => ['Boda boda rider', 'Taxi / matatu driver', 'Truck driver', 'Bus driver', 'Vehicle / motorcycle owner (hire)', 'Mechanic', 'Car wash operator'],
        'Skilled trades' => ['Carpenter', 'Builder / mason', 'Welder', 'Electrician', 'Plumber', 'Painter', 'Bricklayer / brick maker', 'Shoe maker / cobbler', 'Phone repair technician', 'Photographer / videographer', 'Printer / stationery', 'Quarry / sand worker', 'Furniture maker', 'Metal fabricator', 'Miner / artisanal miner', 'Day labourer / casual worker'],
        'Professional & office' => ['Teacher', 'Lecturer', 'Nurse / midwife', 'Clinical officer / doctor', 'Pharmacist', 'Accountant', 'Banker', 'Administrator / clerk', 'Secretary', 'Engineer', 'Lawyer', 'IT / computer technician', 'Civil servant', 'Police / army / prison officer', 'Security guard', 'NGO / project worker', 'Journalist', 'Surveyor', 'Social worker', 'Insurance agent', 'Real estate agent / broker', 'Travel / tour agent', 'Radio / media presenter'],
        'Services' => ['Housekeeper / domestic worker', 'Cleaner', 'Cook / caterer', 'Waiter / waitress', 'Hotel / lodge staff', 'Event planner / decorator', 'Musician / entertainer', 'Hairdresser', 'Laundry / dry cleaner', 'Tour guide', 'Pastor / church worker', 'Imam / religious leader', 'Traditional healer', 'Landlord / rental income'],
        'Other' => ['Student', 'Retired', 'Unemployed', 'Self-employed (other)', 'Employed (other)'],
    ];

    public static function professions(): array
    {
        return array_merge(...array_values(self::PROFESSIONS));
    }

    public static function seedDistricts(): void
    {
        if ((int)val('SELECT COUNT(*) FROM ug_districts') >= count(self::DISTRICTS)) return;
        foreach (self::DISTRICTS as $d) q('INSERT INTO ug_districts(name) VALUES (?) ON CONFLICT (name) DO NOTHING', [$d]);
    }

    /** Child names for a level given the chain chosen so far: names are matched case-insensitively. */
    public static function children(string $level, array $chain): array
    {
        $names = fn(array $r) => array_column($r, 'name');
        if ($level !== 'district') self::ensureBundled();
        switch ($level) {
            case 'district':
                self::seedDistricts();
                self::ensureBundled();
                return $names(rows('SELECT name FROM ug_districts ORDER BY name'));
            case 'county':
                return $names(rows('SELECT c.name FROM ug_counties c JOIN ug_districts d ON d.id = c.district_id WHERE LOWER(d.name) = LOWER(?) ORDER BY c.name', [$chain['district'] ?? '']));
            case 'subcounty':
                return $names(rows('SELECT s.name FROM ug_subcounties s JOIN ug_counties c ON c.id = s.county_id JOIN ug_districts d ON d.id = c.district_id
                                   WHERE LOWER(d.name) = LOWER(?) AND (? = \'\' OR LOWER(c.name) = LOWER(?)) ORDER BY s.name', [$chain['district'] ?? '', $chain['county'] ?? '', $chain['county'] ?? '']));
            case 'parish':
                return $names(rows('SELECT p.name FROM ug_parishes p JOIN ug_subcounties s ON s.id = p.subcounty_id JOIN ug_counties c ON c.id = s.county_id JOIN ug_districts d ON d.id = c.district_id
                                   WHERE LOWER(d.name) = LOWER(?) AND (? = \'\' OR LOWER(c.name) = LOWER(?)) AND LOWER(s.name) = LOWER(?) ORDER BY p.name',
                    [$chain['district'] ?? '', $chain['county'] ?? '', $chain['county'] ?? '', $chain['subcounty'] ?? '']));
            case 'village':
                return $names(rows('SELECT v.name FROM ug_villages v JOIN ug_parishes p ON p.id = v.parish_id JOIN ug_subcounties s ON s.id = p.subcounty_id JOIN ug_counties c ON c.id = s.county_id JOIN ug_districts d ON d.id = c.district_id
                                   WHERE LOWER(d.name) = LOWER(?) AND (? = \'\' OR LOWER(c.name) = LOWER(?)) AND LOWER(s.name) = LOWER(?) AND LOWER(p.name) = LOWER(?) ORDER BY v.name',
                    [$chain['district'] ?? '', $chain['county'] ?? '', $chain['county'] ?? '', $chain['subcounty'] ?? '', $chain['parish'] ?? '']));
        }
        return [];
    }

    /**
     * Import rows [district, county, subcounty, parish, village]; returns the number of rows that reached the deepest level given.
     * Existing names are reused (case-insensitive); villages are added in bulk, so the whole country loads in seconds.
     */
    public static function import(iterable $rows): int
    {
        $n = 0;
        $key = fn(...$p) => mb_strtolower(implode('|', $p));
        $map = ['d' => [], 'c' => [], 's' => [], 'p' => [], 'v' => []];
        foreach (rows('SELECT id, name FROM ug_districts') as $r) $map['d'][$key($r['name'])] = (int)$r['id'];
        foreach (rows('SELECT id, district_id, name FROM ug_counties') as $r) $map['c'][$key($r['district_id'], $r['name'])] = (int)$r['id'];
        foreach (rows('SELECT id, county_id, name FROM ug_subcounties') as $r) $map['s'][$key($r['county_id'], $r['name'])] = (int)$r['id'];
        foreach (rows('SELECT id, subcounty_id, name FROM ug_parishes') as $r) $map['p'][$key($r['subcounty_id'], $r['name'])] = (int)$r['id'];
        foreach (rows('SELECT parish_id, name FROM ug_villages') as $r) $map['v'][$key($r['parish_id'], $r['name'])] = 1;
        $queue = [];
        $flush = function () use (&$queue) {
            foreach (array_chunk($queue, 400) as $chunk) {
                q('INSERT INTO ug_villages(parish_id, name) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?)')) . ' ON CONFLICT (parish_id, name) DO NOTHING', array_merge(...array_map(fn($v) => [$v[0], $v[1]], $chunk)));
            }
            $queue = [];
        };
        tx(function () use ($rows, &$map, $key, &$queue, $flush, &$n) {
            foreach ($rows as $r) {
                [$d, $c, $s, $p, $v] = array_map(fn($x) => trim((string)$x), array_pad(array_values($r), 5, ''));
                if ($d === '') continue;
                $k = $key($d);
                $di = $map['d'][$k] ??= insert('ug_districts', ['name' => $d]);
                if ($c === '') continue;
                $k = $key($di, $c); $ci = $map['c'][$k] ??= insert('ug_counties', ['district_id' => $di, 'name' => $c]);
                if ($s === '') continue;
                $k = $key($ci, $s); $si = $map['s'][$k] ??= insert('ug_subcounties', ['county_id' => $ci, 'name' => $s]);
                if ($p === '') continue;
                $k = $key($si, $p); $pi = $map['p'][$k] ??= insert('ug_parishes', ['subcounty_id' => $si, 'name' => $p]);
                $n++;
                if ($v === '') continue;
                $k = $key($pi, $v);
                if (!isset($map['v'][$k])) { $map['v'][$k] = 1; $queue[] = [$pi, $v]; }
            }
            $flush();
        });
        return $n;
    }

    /** Path of the bundled national data set (CSV, gzip). */
    public static function bundleFile(): string { return BASE_PATH . '/database/data/uganda_locations.csv.gz'; }

    /** Load the bundled counties, sub-counties, parishes and villages once. Returns the rows imported (0 when already loaded or absent). */
    public static function ensureBundled(): int
    {
        if (setting('ug_locations_loaded') === '1' || !is_file(self::bundleFile())) return 0;
        set_setting('ug_locations_loaded', '1', null, false);
        $fh = gzopen(self::bundleFile(), 'rb');
        if (!$fh) return 0;
        $rows = (function () use ($fh) { $first = true; while (($l = gzgets($fh)) !== false) { if ($first) { $first = false; continue; } $r = str_getcsv(rtrim($l, "
"), ',', '"', ''); if ($r) yield $r; } })();
        try { $n = self::import($rows); } finally { gzclose($fh); }
        self::seedDistricts();
        return $n;
    }

    public static function counts(): array
    {
        return ['districts' => (int)val('SELECT COUNT(*) FROM ug_districts'), 'counties' => (int)val('SELECT COUNT(*) FROM ug_counties'), 'subcounties' => (int)val('SELECT COUNT(*) FROM ug_subcounties'),
            'parishes' => (int)val('SELECT COUNT(*) FROM ug_parishes'), 'villages' => (int)val('SELECT COUNT(*) FROM ug_villages')];
    }
}

/**
 * District / county / sub-county / parish / village as linked drop-downs (type to filter; any value can still be typed).
 * $v holds the current values keyed district, county, subcounty, parish, village.
 */
function address_fields(array $v = []): void
{
    $levels = ['district' => 'District / City', 'county' => 'County', 'subcounty' => 'Sub-county / Division', 'parish' => 'Parish / Ward', 'village' => 'Village / Cell'];
    echo '<div class="full addr" data-geo="' . e(url('geo')) . '" style="display:contents">';
    foreach ($levels as $k => $label) {
        $id = 'geo_' . $k;
        echo '<div class="fld span1"><label for="' . $id . '">' . e($label) . '</label><input type="text" id="' . $id . '" name="' . $k . '" value="' . e(old($k, $v[$k] ?? '')) . '" list="dl_' . $k . '" autocomplete="off" data-geo-level="' . $k . '" placeholder="Choose or type"><datalist id="dl_' . $k . '"></datalist></div>';
    }
    echo '</div>';
}

/** Occupation input with the common professions as a searchable drop-down (free text still allowed). */
function profession_field(string $name = 'occupation', string $label = 'Occupation / profession', string $value = ''): void
{
    echo '<div class="fld span2"><label for="f_' . e($name) . '">' . e($label) . '</label><input type="text" id="f_' . e($name) . '" name="' . e($name) . '" value="' . e(old($name, $value)) . '" list="dl_professions" autocomplete="off" placeholder="Choose or type a profession"><datalist id="dl_professions">';
    foreach (Uganda::PROFESSIONS as $group => $items) foreach ($items as $p) echo '<option value="' . e($p) . '" label="' . e($group) . '"></option>';
    echo '</datalist></div>';
}
