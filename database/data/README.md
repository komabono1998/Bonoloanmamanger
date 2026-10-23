# Uganda locations (uganda_locations.csv.gz)

District, county, sub-county, parish and village names used by the address drop-downs
(Administration > Uganda Locations; loaded automatically the first time a drop-down is used).

* Source: `assets/districts.json` of https://github.com/wizlif/akwap_flutter (BSD-3-Clause licence), downloaded 24 Oct 2026 (3,159,591 bytes).
* Converted to a flat CSV (Title Case), two district spellings aligned with the district list (Ssembabule -> Sembabule, Luweero -> Luwero), then gzip-compressed.
* Content: 135 districts, 303 counties, 2,120 sub-counties, 10,365 parishes, 71,250 villages.
* Caveats: the source's authors state it is not 100% accurate, and it predates the newest districts and cities (for example Bugweri, Kassanda, Terego, Madi-Okollo and the 2020 cities), which are listed as districts without sub-units.
  Add or correct units by uploading a CSV (District, County, Sub-county, Parish, Village) on the Uganda Locations page. Any value can also be typed in the forms.
