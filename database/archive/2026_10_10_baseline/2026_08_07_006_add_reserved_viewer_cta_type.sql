INSERT INTO cta_types (action_key, label, description, is_active)
SELECT 'to_reserved_viewer', 'To Reserved Viewer', 'Return to the originating reserved viewer URL', 1
WHERE NOT EXISTS (
  SELECT 1 FROM cta_types WHERE action_key = 'to_reserved_viewer'
);
