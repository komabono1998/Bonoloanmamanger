-- The repayment schedule generated automatically when a loan is approved (projected from the approved terms).
-- At disbursement the final schedule in repayment_schedules is created with the actual dates; this projection is kept for the audit trail.
CREATE TABLE loan_schedule_projection (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  loan_id INTEGER NOT NULL REFERENCES loans(id),
  installment_no INTEGER NOT NULL,
  due_date TEXT NOT NULL,
  principal_due INTEGER NOT NULL,
  interest_due INTEGER NOT NULL,
  total_due INTEGER NOT NULL,
  basis_date TEXT NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (loan_id, installment_no)
);
