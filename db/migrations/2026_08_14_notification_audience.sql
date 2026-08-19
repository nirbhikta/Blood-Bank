-- ---------------------------------------------------------------
-- Split notifications into two audiences.
--
-- Both the admin console and the user site read notifications for the
-- signed-in user_id, so an admin who is also a donor saw their personal
-- "Your donation has been approved!" message on the admin page. audience
-- records who a row was written FOR, independently of who receives it:
--   'user'  personal (approval, eligibility, request status)  -> notification.html
--   'admin' operational alerts from notifyAdmins()            -> admin/adnotification.html
--
-- Idempotent: safe to run more than once on MariaDB / MySQL 8.
-- ---------------------------------------------------------------

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS audience ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER user_id;

-- Backfill: the titles below are the only ones notifyAdmins() ever writes.
UPDATE notifications
SET audience = 'admin'
WHERE title IN (
  'New donor registered',
  'Low inventory alert',
  'Donor Eligibility Review Required',
  'New blood request',
  'Critical blood request'
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_audience ON notifications (user_id, audience);
