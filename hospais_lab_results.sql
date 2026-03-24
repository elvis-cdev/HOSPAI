-- ════════════════════════════════════════════════════════════════
-- HospAIs Lab Results Table
-- Run this ONCE in your InfinityFree MySQL database
-- Panel: infinityfree.com → MySQL Databases → PhpMyAdmin → SQL tab
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS lab_results (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    patient_id       VARCHAR(20),
    patient_name     VARCHAR(100),
    phone            VARCHAR(20),
    email            VARCHAR(100),
    test_name        VARCHAR(100),
    result_value     VARCHAR(100),
    result_status    ENUM('normal','abnormal','critical','pending') DEFAULT 'pending',
    reference_range  VARCHAR(100),
    notes            TEXT,
    ai_message       TEXT,
    notified         TINYINT DEFAULT 0,
    notified_at      DATETIME,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient (patient_id),
    INDEX idx_notified (notified)
);

-- Optional: seed with sample data to test immediately
-- Replace P001, P002 with real patient IDs from your patients table

INSERT IGNORE INTO lab_results (patient_id, patient_name, test_name, result_value, result_status, reference_range)
SELECT patient_id, full_name, 'Malaria RDT', 'Positive', 'abnormal', 'Negative'
FROM patients LIMIT 1;

INSERT IGNORE INTO lab_results (patient_id, patient_name, test_name, result_value, result_status, reference_range)
SELECT patient_id, full_name, 'Blood Sugar (FBS)', '5.1 mmol/L', 'normal', '3.9-6.1 mmol/L'
FROM patients LIMIT 1 OFFSET 0;

SELECT 'lab_results table created successfully' AS status;
