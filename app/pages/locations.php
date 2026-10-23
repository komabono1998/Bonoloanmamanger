<?php
/** Uganda locations: upload counties, sub-counties, parishes and villages so they appear in the address drop-downs. */
$title = 'Uganda Locations';
Uganda::seedDistricts();

if (get('a') === 'import') post_action(function () {
    require_perm('settings.manage');
    must(!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']), 'Choose a CSV file to import.');
    $fh = fopen($_FILES['file']['tmp_name'], 'r');
    must($fh !== false, 'The file could not be read.');
    $first = fgets($fh); rewind($fh);
    $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : (substr_count((string)$first, "\t") > substr_count((string)$first, ',') ? "\t" : ',');
    $rows = (function () use ($fh, $delim) {
        $i = 0;
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            if ($i++ === 0 && isset($r[0])) { $h = mb_strtolower(trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $r[0]))); if (str_contains($h, 'district')) continue; }   // header row
            yield $r;
        }
    })();
    $n = Uganda::import($rows);
    fclose($fh);
    audit('Uganda locations imported', 'Administration', 'locations', 'import', null, $n . ' rows');
    return [$n . ' location row(s) imported.', url('locations')];
});

$justLoaded = Uganda::ensureBundled();
$c = Uganda::counts();
page_head('Uganda Locations', '', 'District, county, sub-county, parish and village lists that feed the address drop-downs on client and guarantor forms.');
if ($justLoaded) echo '<div class="notice ok">The national data set was loaded: ' . num($justLoaded) . ' rows.</div>';
echo '<div class="kpis">' . kpi('Districts & cities', num($c['districts'])) . kpi('Counties', num($c['counties'])) . kpi('Sub-counties', num($c['subcounties'])) . kpi('Parishes', num($c['parishes'])) . kpi('Villages', num($c['villages'])) . '</div>';
echo '<div class="card"><h2>Import locations</h2><p class="muted">The system ships with the national lists (source and caveats in <code>database/data/README.md</code>); they are not complete for the newest districts and cities, so add or correct units here. Upload a CSV with five columns: <b>District, County, Sub-county, Parish, Village</b> (one row per village; a header row is optional). Existing names are kept, new ones are added. Until counties, parishes and villages are loaded, those boxes still accept typed values.</p>';
form_open(url('locations', ['a' => 'import']), ['upload' => true]);
field('file', 'CSV file', ['type' => 'file', 'span' => 2, 'accept' => 'accept=".csv,text/csv,text/plain"']);
echo '<div class="form-actions">'; submit_btn('Import'); echo '</div>'; form_close(); echo '</div>';
