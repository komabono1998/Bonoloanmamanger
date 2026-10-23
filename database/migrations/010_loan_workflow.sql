-- Harmonised loan application workflow: verification of KYC, documents and guarantors, stage exceptions, application versions, pre-disbursement record and a workflow log.
ALTER TABLE clients ADD COLUMN kyc_status TEXT NOT NULL DEFAULT 'Pending';
ALTER TABLE clients ADD COLUMN kyc_verified_by INTEGER;
ALTER TABLE clients ADD COLUMN kyc_verified_at TEXT;
ALTER TABLE clients ADD COLUMN kyc_note TEXT;
ALTER TABLE attachments ADD COLUMN verify_status TEXT NOT NULL DEFAULT 'Pending';
ALTER TABLE attachments ADD COLUMN verified_by INTEGER;
ALTER TABLE attachments ADD COLUMN verified_at TEXT;
ALTER TABLE attachments ADD COLUMN verify_note TEXT;
ALTER TABLE loan_guarantors ADD COLUMN verify_status TEXT NOT NULL DEFAULT 'Pending';
ALTER TABLE loan_guarantors ADD COLUMN verified_by INTEGER;
ALTER TABLE loan_guarantors ADD COLUMN verified_at TEXT;
ALTER TABLE loan_guarantors ADD COLUMN verify_note TEXT;
ALTER TABLE loans ADD COLUMN app_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE loans ADD COLUMN approved_version INTEGER;
ALTER TABLE loans ADD COLUMN predisb_at TEXT;
ALTER TABLE loans ADD COLUMN predisb_by INTEGER;
ALTER TABLE loans ADD COLUMN return_stage TEXT;
ALTER TABLE loans ADD COLUMN guarantor_na_reason TEXT;
ALTER TABLE loans ADD COLUMN guarantor_na_by INTEGER;
ALTER TABLE loans ADD COLUMN collateral_na_reason TEXT;
ALTER TABLE loans ADD COLUMN collateral_na_by INTEGER;
ALTER TABLE loans ADD COLUMN relied_on TEXT;

-- everything recorded before the workflow existed is treated as already verified
UPDATE clients SET kyc_status = 'Verified', kyc_note = 'Recorded before the workflow was introduced', kyc_verified_at = registered_at;
UPDATE attachments SET verify_status = 'Verified';
UPDATE loan_guarantors SET verify_status = 'Verified';
UPDATE loans SET approved_version = 1 WHERE approved_by IS NOT NULL;

CREATE TABLE loan_workflow_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  loan_id INTEGER NOT NULL REFERENCES loans(id),
  stage TEXT NOT NULL,
  event TEXT NOT NULL,
  reason TEXT,
  user_id INTEGER,
  at TEXT NOT NULL
);
CREATE INDEX ix_wflog_loan ON loan_workflow_log(loan_id);
