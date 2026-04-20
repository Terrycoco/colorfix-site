ALTER TABLE photo_alt_text_jobs
  ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'gemini' AFTER max_attempts,
  ADD COLUMN provider_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER provider,
  ADD COLUMN first_attempt_at DATETIME NULL AFTER provider_attempts;
