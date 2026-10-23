-- Record of every administrator reset of the End-of-Day position (a closed business date reopened after an error).
CREATE TABLE eod_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  branch_id INTEGER NOT NULL REFERENCES branches(id),
  reopened_date TEXT NOT NULL,
  removed_date TEXT,
  reason TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  at TEXT NOT NULL
);
