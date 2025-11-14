-- Drop duplicate columns
ALTER TABLE outage_reports
DROP COLUMN report_date,
DROP COLUMN resolved_date,
DROP COLUMN notes; 