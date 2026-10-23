CREATE TABLE report_role_access (
  role TEXT NOT NULL,
  report_id TEXT NOT NULL,
  allowed INTEGER NOT NULL,
  updated_by INTEGER,
  updated_at TEXT,
  PRIMARY KEY (role, report_id)
);
DELETE FROM user_report_access;
ALTER TABLE branches ADD COLUMN collection_target INTEGER;
ALTER TABLE users ADD COLUMN collection_target INTEGER;
