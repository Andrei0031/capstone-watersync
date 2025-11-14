ALTER TABLE outage_reports 
ADD COLUMN resolution_notes TEXT NULL AFTER description,
ADD COLUMN resolved_at TIMESTAMP NULL AFTER resolution_notes; 