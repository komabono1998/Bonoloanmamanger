CREATE TABLE password_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  token_hash TEXT NOT NULL UNIQUE,
  purpose TEXT NOT NULL,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT,
  requested_by INTEGER
);
CREATE INDEX ix_password_resets_user ON password_resets(user_id);

CREATE TABLE user_report_access (
  user_id INTEGER NOT NULL REFERENCES users(id),
  report_key TEXT NOT NULL,
  allowed INTEGER NOT NULL,
  PRIMARY KEY (user_id, report_key)
);
