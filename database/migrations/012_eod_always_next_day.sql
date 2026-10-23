-- The system date always moves to the next working day when End of Day closes the business date.
UPDATE settings SET value = '1' WHERE key = 'eod_auto_open_next';
