USE roadline;

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
