-- Run once on existing Roadline installations before deploying profile updates.
-- This only refreshes the updatable user view; it does not modify user records.

USE roadzyrf_roadline;

CREATE OR REPLACE VIEW roadline_app_users AS
SELECT
    User_ID AS id,
    Display_Name AS name,
    First_Name AS first_name,
    middle_name AS middle_name,
    Last_Name AS last_name,
    Contact AS contact,
    Email AS email,
    Password_Hash AS password_hash,
    Role AS role,
    Is_Active AS is_active,
    Deleted_At AS deleted_at,
    Timestamp AS created_at
FROM Users;
