-- Reviewed opt-in migration; never run automatically. Existing actors remain administrators.
-- admin_id remains the legacy actor-ID field; actor_role identifies admins versus users(librarian).
ALTER TABLE sms_notification_audit ADD COLUMN actor_role ENUM('admin','librarian') NOT NULL DEFAULT 'admin' AFTER admin_id;
