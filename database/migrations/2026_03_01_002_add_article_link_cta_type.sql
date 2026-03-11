INSERT INTO cta_types (action_key, label, description, is_active)
SELECT 'article_link', 'Article Link', 'Link back to a specific article (title + dek)', 1
WHERE NOT EXISTS (
  SELECT 1 FROM cta_types WHERE action_key = 'article_link'
);
