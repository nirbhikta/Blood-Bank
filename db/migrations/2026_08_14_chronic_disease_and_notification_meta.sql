

ALTER TABLE donations
  ADD COLUMN IF NOT EXISTS has_chronic_disease TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN IF NOT EXISTS chronic_disease_details TEXT NULL AFTER has_chronic_disease,
  ADD COLUMN IF NOT EXISTS eligibility ENUM('Unreviewed','Eligible','Ineligible')
      NOT NULL DEFAULT 'Unreviewed' AFTER chronic_disease_details,
  ADD COLUMN IF NOT EXISTS appointment_date DATE NULL AFTER eligibility,
  ADD COLUMN IF NOT EXISTS appointment_time TIME NULL AFTER appointment_date;


ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS meta TEXT NULL AFTER message;
