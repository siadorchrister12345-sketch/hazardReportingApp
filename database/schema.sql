CREATE DATABASE IF NOT EXISTS roadline
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE roadline;

CREATE TABLE IF NOT EXISTS Users (
    User_ID INT NOT NULL AUTO_INCREMENT,
    First_Name VARCHAR(25) NULL,
    middle_name VARCHAR(25) NULL,
    Last_Name VARCHAR(25) NULL,
    Role VARCHAR(30) NULL,
    Contact VARCHAR(254) NULL,
    Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    Display_Name VARCHAR(120) NOT NULL DEFAULT '',
    Email VARCHAR(190) NULL,
    Password_Hash VARCHAR(255) NULL,
    Is_Active TINYINT(1) NOT NULL DEFAULT 1,
    Deleted_At DATETIME NULL,
    PRIMARY KEY (User_ID),
    UNIQUE KEY users_email_unique (Email),
    KEY users_role_active (Role, Is_Active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Hazards (
    Hazard_ID INT NOT NULL AUTO_INCREMENT,
    User_ID INT NULL,
    Coordinates GEOMETRY NULL,
    Photo_URL VARCHAR(2048) NULL,
    Status VARCHAR(40) NOT NULL DEFAULT 'pending_review',
    Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    Public_ID CHAR(12) NOT NULL,
    Road_Location_ID VARCHAR(191) NULL,
    Road_Location_Label VARCHAR(255) NOT NULL DEFAULT '',
    Latitude DECIMAL(10,7) NULL,
    Longitude DECIMAL(10,7) NULL,
    Category VARCHAR(80) NOT NULL DEFAULT 'Other road hazard',
    Title VARCHAR(160) NOT NULL DEFAULT 'Road hazard report',
    Description TEXT NULL,
    Reviewed_By INT NULL,
    Reviewed_At DATETIME NULL,
    Updated_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Hazard_ID),
    UNIQUE KEY hazards_public_id_unique (Public_ID),
    KEY hazards_reporter_created (User_ID, Timestamp),
    KEY hazards_status_created (Status, Timestamp),
    CONSTRAINT FK_Hazards_Users FOREIGN KEY (User_ID) REFERENCES Users (User_ID),
    CONSTRAINT FK_Hazards_Reviewer FOREIGN KEY (Reviewed_By) REFERENCES Users (User_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Work_Orders (
    Order_ID INT NOT NULL AUTO_INCREMENT,
    Hazard_ID INT NULL,
    User_ID INT NULL,
    Date_Assigned DATE NULL,
    Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    Assigned_By INT NULL,
    Status VARCHAR(40) NOT NULL DEFAULT 'pending_assignment',
    Admin_Notes VARCHAR(1000) NULL,
    Worker_Notes VARCHAR(1000) NULL,
    Completed_At DATETIME NULL,
    Verified_At DATETIME NULL,
    Updated_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Order_ID),
    KEY work_orders_hazard (Hazard_ID),
    KEY work_orders_worker_status (User_ID, Status),
    CONSTRAINT FK_WorkOrders_Hazards FOREIGN KEY (Hazard_ID) REFERENCES Hazards (Hazard_ID),
    CONSTRAINT FK_WorkOrders_Users FOREIGN KEY (User_ID) REFERENCES Users (User_ID),
    CONSTRAINT FK_WorkOrders_Assigner FOREIGN KEY (Assigned_By) REFERENCES Users (User_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Updates (
    Update_ID INT NOT NULL AUTO_INCREMENT,
    Hazard_ID INT NULL,
    Description VARCHAR(10000) NULL,
    Photo_URL VARCHAR(2048) NULL,
    Timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    Recipient_User_ID INT NULL,
    Read_At DATETIME NULL,
    PRIMARY KEY (Update_ID),
    KEY updates_hazard (Hazard_ID),
    KEY updates_recipient_unread (Recipient_User_ID, Read_At, Timestamp),
    CONSTRAINT FK_Updates_Hazards FOREIGN KEY (Hazard_ID) REFERENCES Hazards (Hazard_ID),
    CONSTRAINT FK_Updates_Recipient FOREIGN KEY (Recipient_User_ID) REFERENCES Users (User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roadline_conversation_messages (
    id INT NOT NULL AUTO_INCREMENT,
    report_id INT NOT NULL,
    sender_user_id INT NOT NULL,
    message VARCHAR(2000) NOT NULL,
    photo_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conversation_report_created (report_id, created_at, id),
    CONSTRAINT FK_Conversation_Hazards FOREIGN KEY (report_id) REFERENCES Hazards (Hazard_ID) ON DELETE CASCADE,
    CONSTRAINT FK_Conversation_Users FOREIGN KEY (sender_user_id) REFERENCES Users (User_ID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW roadline_app_users AS
SELECT
    User_ID AS id,
    Display_Name AS name,
    Email AS email,
    Password_Hash AS password_hash,
    Role AS role,
    Is_Active AS is_active,
    Deleted_At AS deleted_at,
    Timestamp AS created_at
FROM Users;

CREATE OR REPLACE VIEW roadline_reports AS
SELECT
    Hazard_ID AS id,
    Public_ID AS public_id,
    User_ID AS reporter_id,
    Road_Location_ID AS road_location_id,
    Road_Location_Label AS road_location_label,
    Latitude AS latitude,
    Longitude AS longitude,
    Category AS category,
    Title AS title,
    Description AS description,
    Photo_URL AS photo_path,
    Status AS status,
    Reviewed_By AS reviewed_by,
    Reviewed_At AS reviewed_at,
    Timestamp AS created_at,
    Updated_At AS updated_at
FROM Hazards;

CREATE OR REPLACE VIEW roadline_jobs AS
SELECT
    Order_ID AS id,
    Hazard_ID AS report_id,
    Assigned_By AS assigned_by,
    User_ID AS worker_id,
    Status AS status,
    Admin_Notes AS admin_notes,
    Worker_Notes AS worker_notes,
    Completed_At AS completed_at,
    Verified_At AS verified_at,
    Timestamp AS created_at,
    Updated_At AS updated_at
FROM Work_Orders;

CREATE OR REPLACE VIEW roadline_notifications AS
SELECT
    Update_ID AS id,
    Recipient_User_ID AS user_id,
    Hazard_ID AS report_id,
    Description AS message,
    Photo_URL AS photo_path,
    Read_At AS read_at,
    Timestamp AS created_at
FROM Updates;
