-- Run this once against an existing database created from the original Roadline ERD.
-- Back up the database first. This only adds application fields to the four ERD
-- tables, then creates compatibility views; it does not delete existing rows.

USE roadline;

ALTER TABLE Users
    MODIFY User_ID INT NOT NULL AUTO_INCREMENT,
    ADD COLUMN Display_Name VARCHAR(120) NOT NULL DEFAULT '' AFTER Timestamp,
    ADD COLUMN Email VARCHAR(190) NULL AFTER Display_Name,
    ADD COLUMN Password_Hash VARCHAR(255) NULL AFTER Email,
    ADD COLUMN Is_Active TINYINT(1) NOT NULL DEFAULT 1 AFTER Password_Hash,
    ADD COLUMN Deleted_At DATETIME NULL AFTER Is_Active,
    MODIFY Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE Users
SET Display_Name = TRIM(CONCAT_WS(' ', First_Name, middle_name, Last_Name))
WHERE Display_Name = '';

ALTER TABLE Users
    ADD UNIQUE KEY users_email_unique (Email),
    ADD KEY users_role_active (Role, Is_Active);

ALTER TABLE Hazards
    MODIFY Hazard_ID INT NOT NULL AUTO_INCREMENT,
    MODIFY Status VARCHAR(40) NULL DEFAULT 'pending_review',
    ADD COLUMN Public_ID CHAR(12) NULL AFTER Timestamp,
    ADD COLUMN Road_Location_ID VARCHAR(191) NULL AFTER Public_ID,
    ADD COLUMN Road_Location_Label VARCHAR(255) NOT NULL DEFAULT '' AFTER Road_Location_ID,
    ADD COLUMN Latitude DECIMAL(10,7) NULL AFTER Road_Location_Label,
    ADD COLUMN Longitude DECIMAL(10,7) NULL AFTER Latitude,
    ADD COLUMN Category VARCHAR(80) NOT NULL DEFAULT 'Other road hazard' AFTER Longitude,
    ADD COLUMN Title VARCHAR(160) NOT NULL DEFAULT 'Road hazard report' AFTER Category,
    ADD COLUMN Description TEXT NULL AFTER Title,
    ADD COLUMN Reviewed_By INT NULL AFTER Description,
    ADD COLUMN Reviewed_At DATETIME NULL AFTER Reviewed_By,
    ADD COLUMN Updated_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER Reviewed_At;

UPDATE Hazards
SET Public_ID = LPAD(Hazard_ID, 12, '0')
WHERE Public_ID IS NULL;

UPDATE Hazards
SET Status = 'pending_review'
WHERE Status IS NULL OR TRIM(Status) = '';

ALTER TABLE Hazards
    MODIFY Public_ID CHAR(12) NOT NULL,
    MODIFY Status VARCHAR(40) NOT NULL DEFAULT 'pending_review',
    ADD UNIQUE KEY hazards_public_id_unique (Public_ID),
    ADD KEY hazards_reporter_created (User_ID, Timestamp),
    ADD KEY hazards_status_created (Status, Timestamp),
    ADD CONSTRAINT FK_Hazards_Reviewer FOREIGN KEY (Reviewed_By) REFERENCES Users (User_ID) ON DELETE SET NULL;

ALTER TABLE Work_Orders
    MODIFY Order_ID INT NOT NULL AUTO_INCREMENT,
    ADD COLUMN Assigned_By INT NULL AFTER Timestamp,
    ADD COLUMN Status VARCHAR(40) NOT NULL DEFAULT 'pending_assignment' AFTER Assigned_By,
    ADD COLUMN Admin_Notes VARCHAR(1000) NULL AFTER Status,
    ADD COLUMN Worker_Notes VARCHAR(1000) NULL AFTER Admin_Notes,
    ADD COLUMN Completed_At DATETIME NULL AFTER Worker_Notes,
    ADD COLUMN Verified_At DATETIME NULL AFTER Completed_At,
    ADD COLUMN Updated_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER Verified_At,
    MODIFY Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE Work_Orders
    ADD KEY work_orders_worker_status (User_ID, Status),
    ADD CONSTRAINT FK_WorkOrders_Assigner FOREIGN KEY (Assigned_By) REFERENCES Users (User_ID) ON DELETE SET NULL;

UPDATE Work_Orders
SET Status = 'assigned'
WHERE User_ID IS NOT NULL AND Status = 'pending_assignment';

ALTER TABLE Updates
    MODIFY Update_ID INT NOT NULL AUTO_INCREMENT,
    ADD COLUMN Photo_URL VARCHAR(2048) NULL AFTER Description,
    ADD COLUMN Recipient_User_ID INT NULL AFTER Timestamp,
    ADD COLUMN Read_At DATETIME NULL AFTER Recipient_User_ID,
    MODIFY Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE Updates
    ADD KEY updates_recipient_unread (Recipient_User_ID, Read_At, Timestamp),
    ADD CONSTRAINT FK_Updates_Recipient FOREIGN KEY (Recipient_User_ID) REFERENCES Users (User_ID) ON DELETE CASCADE;

CREATE OR REPLACE VIEW roadline_app_users AS
SELECT User_ID AS id, Display_Name AS name, Email AS email,
       Password_Hash AS password_hash, Role AS role,
       Is_Active AS is_active, Deleted_At AS deleted_at,
       Timestamp AS created_at
FROM Users;

CREATE OR REPLACE VIEW roadline_reports AS
SELECT Hazard_ID AS id, Public_ID AS public_id, User_ID AS reporter_id,
       Road_Location_ID AS road_location_id,
       Road_Location_Label AS road_location_label, Latitude AS latitude,
       Longitude AS longitude, Category AS category, Title AS title,
       Description AS description, Photo_URL AS photo_path, Status AS status,
       Reviewed_By AS reviewed_by, Reviewed_At AS reviewed_at,
       Timestamp AS created_at, Updated_At AS updated_at
FROM Hazards;

CREATE OR REPLACE VIEW roadline_jobs AS
SELECT Order_ID AS id, Hazard_ID AS report_id, Assigned_By AS assigned_by,
       User_ID AS worker_id, Status AS status, Admin_Notes AS admin_notes,
       Worker_Notes AS worker_notes, Completed_At AS completed_at,
       Verified_At AS verified_at, Timestamp AS created_at,
       Updated_At AS updated_at
FROM Work_Orders;

CREATE OR REPLACE VIEW roadline_notifications AS
SELECT Update_ID AS id, Recipient_User_ID AS user_id,
       Hazard_ID AS report_id, Description AS message, Photo_URL AS photo_path,
       Read_At AS read_at, Timestamp AS created_at
FROM Updates;
