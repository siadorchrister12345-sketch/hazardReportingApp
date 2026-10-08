-- Run once on an existing Roadline installation that already applied
-- migrate_roadline_erd.sql. Back up the database first.

USE roadline;

ALTER TABLE Updates
    ADD COLUMN Photo_URL VARCHAR(2048) NULL AFTER Description;

CREATE OR REPLACE VIEW roadline_notifications AS
SELECT Update_ID AS id, Recipient_User_ID AS user_id,
       Hazard_ID AS report_id, Description AS message, Photo_URL AS photo_path,
       Read_At AS read_at, Timestamp AS created_at
FROM Updates;
