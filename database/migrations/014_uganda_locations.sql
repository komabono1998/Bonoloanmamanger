-- Uganda administrative units for drop-down address entry: district > county > sub-county > parish > village.
CREATE TABLE ug_districts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);
CREATE TABLE ug_counties (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  district_id INTEGER NOT NULL REFERENCES ug_districts(id),
  name TEXT NOT NULL,
  UNIQUE (district_id, name)
);
CREATE TABLE ug_subcounties (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  county_id INTEGER NOT NULL REFERENCES ug_counties(id),
  name TEXT NOT NULL,
  UNIQUE (county_id, name)
);
CREATE TABLE ug_parishes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subcounty_id INTEGER NOT NULL REFERENCES ug_subcounties(id),
  name TEXT NOT NULL,
  UNIQUE (subcounty_id, name)
);
CREATE TABLE ug_villages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  parish_id INTEGER NOT NULL REFERENCES ug_parishes(id),
  name TEXT NOT NULL,
  UNIQUE (parish_id, name)
);
ALTER TABLE guarantors ADD COLUMN county TEXT;
