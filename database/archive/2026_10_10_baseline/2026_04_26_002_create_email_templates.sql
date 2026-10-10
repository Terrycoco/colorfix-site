CREATE TABLE IF NOT EXISTS email_templates (
  email_template_id INT NOT NULL AUTO_INCREMENT,
  template_key VARCHAR(120) NOT NULL,
  label VARCHAR(190) NOT NULL,
  subject_template TEXT NULL,
  message_template MEDIUMTEXT NULL,
  html_template MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (email_template_id),
  UNIQUE KEY uniq_email_templates_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
