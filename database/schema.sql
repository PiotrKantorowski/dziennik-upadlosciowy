CREATE TABLE IF NOT EXISTS subjects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(512) NOT NULL,
  krs VARCHAR(20) NULL,
  nip VARCHAR(20) NULL,
  regon VARCHAR(20) NULL,
  pesel VARCHAR(20) NULL,
  aliases TEXT NULL,
  type ENUM('company','business_person','natural_person','unknown') NOT NULL DEFAULT 'unknown',
  email VARCHAR(255) NULL,
  service_mode ENUM('office_monitoring','client_monitoring','one_time') NULL,
  monitored TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME NULL,
  last_status VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX idx_subject_identifiers (krs,nip,regon,pesel),
  INDEX idx_subject_monitored (monitored)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(16) NOT NULL,
  status VARCHAR(32) NOT NULL,
  message TEXT NULL,
  raw_json JSON NULL,
  checked_at DATETIME NOT NULL,
  INDEX idx_checks_subject_source (subject_id,source,checked_at),
  CONSTRAINT fk_checks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(16) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  title VARCHAR(512) NOT NULL,
  description MEDIUMTEXT NULL,
  signature VARCHAR(128) NULL,
  publication_date DATE NULL,
  proceeding_status VARCHAR(128) NULL,
  risk ENUM('niski','średni','wysoki','krytyczny') NOT NULL DEFAULT 'niski',
  risk_reason TEXT NULL,
  source_url TEXT NULL,
  dedupe_hash CHAR(64) NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_event_dedupe (dedupe_hash),
  INDEX idx_events_subject_source (subject_id,source,created_at),
  INDEX idx_events_risk (risk),
  CONSTRAINT fk_events_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS krz_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NOT NULL,
  query VARCHAR(255) NOT NULL,
  query_key VARCHAR(32) NOT NULL,
  search_kind VARCHAR(32) NOT NULL,
  status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  error TEXT NULL,
  raw_json JSON NULL,
  claimed_by VARCHAR(32) NULL,
  INDEX idx_krz_status (status,requested_at),
  INDEX idx_krz_pending (subject_id,query_key,status),
  CONSTRAINT fk_krz_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS msig_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NOT NULL,
  query VARCHAR(255) NOT NULL,
  query_key VARCHAR(32) NOT NULL,
  search_kind VARCHAR(32) NOT NULL,
  status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  error TEXT NULL,
  raw_json JSON NULL,
  claimed_by VARCHAR(32) NULL,
  INDEX idx_msig_status (status,requested_at),
  INDEX idx_msig_pending (subject_id,query_key,status),
  CONSTRAINT fk_msig_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_statement_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  submitted_at DATE NULL,
  due_date DATE NULL,
  status VARCHAR(32) NOT NULL,
  reason TEXT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_fin_subject (subject_id,created_at),
  CONSTRAINT fk_fin_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(512) NOT NULL,
  summary TEXT NULL,
  html MEDIUMTEXT NULL,
  pdf_path TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_reports_type (type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outgoing_mail (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id BIGINT UNSIGNED NULL,
  recipient TEXT NOT NULL,
  subject VARCHAR(512) NOT NULL,
  status VARCHAR(32) NOT NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mail_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(128) PRIMARY KEY,
  value MEDIUMTEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(128) NOT NULL,
  table_name VARCHAR(128) NULL,
  row_id BIGINT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_action (action,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
