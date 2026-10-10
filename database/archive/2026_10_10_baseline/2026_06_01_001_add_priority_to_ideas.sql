ALTER TABLE ideas
  ADD COLUMN priority CHAR(1) NOT NULL DEFAULT 'B' AFTER idea_id;

UPDATE ideas
   SET priority = 'B'
 WHERE priority NOT IN ('A', 'B', 'C')
    OR priority IS NULL
    OR priority = '';
