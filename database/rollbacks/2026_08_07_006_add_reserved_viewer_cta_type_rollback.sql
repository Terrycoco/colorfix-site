DELETE FROM cta_types
WHERE action_key = 'to_reserved_viewer'
  AND NOT EXISTS (
    SELECT 1 FROM ctas c
    WHERE c.cta_type_id = cta_types.cta_type_id
  );
