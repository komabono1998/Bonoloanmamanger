-- General ledger: chart of accounts and double-entry journals (append-only; corrections are posted as reversing journals).
CREATE TABLE gl_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('asset','liability','equity','income','expense')),
  description TEXT,
  is_system INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE gl_journals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  journal_no TEXT NOT NULL UNIQUE,
  journal_date TEXT NOT NULL,
  branch_id INTEGER REFERENCES branches(id),
  source TEXT NOT NULL DEFAULT 'auto' CHECK (source IN ('auto','manual')),
  src_key TEXT UNIQUE,
  memo TEXT,
  reverses_id INTEGER REFERENCES gl_journals(id),
  posted_by INTEGER,
  created_at TEXT NOT NULL
);
CREATE INDEX ix_gl_journals_date ON gl_journals(journal_date, branch_id);

CREATE TABLE gl_journal_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  journal_id INTEGER NOT NULL REFERENCES gl_journals(id),
  account_id INTEGER NOT NULL REFERENCES gl_accounts(id),
  branch_id INTEGER REFERENCES branches(id),
  debit INTEGER NOT NULL DEFAULT 0 CHECK (debit >= 0),
  credit INTEGER NOT NULL DEFAULT 0 CHECK (credit >= 0),
  description TEXT
);
CREATE INDEX ix_gl_lines_journal ON gl_journal_lines(journal_id);
CREATE INDEX ix_gl_lines_account ON gl_journal_lines(account_id, branch_id);
