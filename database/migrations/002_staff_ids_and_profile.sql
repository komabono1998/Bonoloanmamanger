-- v3.1: unique staff IDs for every user (e.g. EMG-S-0001). Existing users are numbered by the application on first start.
ALTER TABLE users ADD COLUMN staff_no TEXT;
CREATE UNIQUE INDEX ux_users_staff_no ON users(staff_no) WHERE staff_no IS NOT NULL;
