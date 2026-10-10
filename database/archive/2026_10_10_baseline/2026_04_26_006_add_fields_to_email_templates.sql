ALTER TABLE email_templates
  ADD COLUMN description TEXT NULL AFTER label,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER html_template;
