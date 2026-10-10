UPDATE clients
SET first_name = 'Unknown'
WHERE TRIM(COALESCE(first_name, '')) = '';

UPDATE clients
SET last_name = 'Unknown'
WHERE TRIM(COALESCE(last_name, '')) = '';
