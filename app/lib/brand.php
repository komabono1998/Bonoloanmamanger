<?php
/**
 * Institution branding. One profile (Settings -> Institution profile) feeds the letterhead of every receipt, statement,
 * report, voucher, form, export and the sign-in page, so changing the address or logo once changes them everywhere.
 */

/** Public URL of the institution logo (served without sign-in so it also shows on the login page and print-outs). */
function logo_url(): ?string
{
    return setting('business_logo') !== '' ? url('logo', ['v' => setting('business_logo')]) : null;
}

function brand(): array
{
    $addr = array_filter([setting('business_address'), setting('business_city'), setting('business_country')]);
    $contact = array_filter([setting('business_phone'), setting('business_phone2'), setting('business_email'), setting('business_website')]);
    $ids = array_filter([setting('registration_no') !== '' ? 'Reg. No. ' . setting('registration_no') : '', setting('tax_id') !== '' ? 'TIN ' . setting('tax_id') : '']);
    return ['name' => setting('business_name'), 'address' => implode(', ', $addr), 'contact' => implode('  ·  ', $contact), 'ids' => implode('  ·  ', $ids),
        'tagline' => setting('business_tagline'), 'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', setting('brand_color')) ? setting('brand_color') : '#0b5c4d', 'logo' => logo_url()];
}

/** Letterhead block used on every printed / exported document. */
function letterhead(string $title = '', string $sub = ''): string
{
    $b = brand();
    $h = '<div class="lh" style="border-bottom:3px solid ' . e($b['color']) . '">';
    if ($b['logo']) $h .= '<img class="lh-logo" src="' . e($b['logo']) . '" alt="">';
    $h .= '<div class="lh-text"><div class="lh-name" style="color:' . e($b['color']) . '">' . e($b['name']) . '</div>';
    if ($b['tagline']) $h .= '<div class="lh-tag">' . e($b['tagline']) . '</div>';
    if ($b['address']) $h .= '<div class="lh-line">' . e($b['address']) . '</div>';
    if ($b['contact']) $h .= '<div class="lh-line">' . e($b['contact']) . '</div>';
    if ($b['ids']) $h .= '<div class="lh-line lh-ids">' . e($b['ids']) . '</div>';
    $h .= '</div></div>';
    if ($title !== '') $h .= '<h2 class="lh-title">' . e($title) . '</h2>' . ($sub !== '' ? '<p class="lh-sub">' . e($sub) . '</p>' : '');
    return $h;
}

/** Lines for the top of an exported sheet (Excel). */
function letterhead_lines(): array
{
    $b = brand();
    return array_values(array_filter([$b['name'], $b['address'], $b['contact'], $b['ids']]));
}

/** Short code for numbering, e.g. "EMglo FInance" -> EF, "Bono" -> BON. */
function institution_code_from(string $name): string
{
    $words = preg_split('/[^A-Za-z0-9]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $code = count($words) >= 2 ? implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 4))) : substr($words[0] ?? 'BLM', 0, 3);
    return strtoupper($code) ?: 'BLM';
}

/** What is still missing in the institution profile (drives the admin checklist). */
function profile_gaps(): array
{
    $gaps = [];
    foreach (['business_address' => 'address', 'business_phone' => 'telephone', 'business_email' => 'email'] as $k => $l) if (setting($k) === '') $gaps[] = $l;
    if (setting('business_logo') === '') $gaps[] = 'logo';
    return $gaps;
}
