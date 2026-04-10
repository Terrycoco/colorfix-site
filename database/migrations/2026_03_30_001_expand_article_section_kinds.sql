START TRANSACTION;

ALTER TABLE article_sections
    MODIFY COLUMN kind ENUM('text','image','image_text','palette_link','cta','embed','list','header') NOT NULL DEFAULT 'text';

COMMIT;
