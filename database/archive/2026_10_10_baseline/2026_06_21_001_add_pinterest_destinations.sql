UPDATE publishing_channels
SET metadata_json = JSON_SET(
  COALESCE(metadata_json, JSON_OBJECT()),
  '$.environment',
  'production',
  '$.destinations.production',
  JSON_OBJECT(
    'destination_key', 'colorfix_makeovers',
    'environment', 'production',
    'board_id', JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, JSON_OBJECT()), '$.board_id')),
    'board_name', 'ColorFix Makeovers',
    'board_url', 'https://www.pinterest.com/terrymarr/colorfix-makeovers/',
    'board_slug', 'terrymarr/colorfix-makeovers'
  ),
  '$.destinations.test',
  JSON_OBJECT(
    'destination_key', 'colorfix_api_test',
    'environment', 'test',
    'board_id', CAST(NULL AS CHAR),
    'board_name', 'ColorFix API Test',
    'board_url', CAST(NULL AS CHAR),
    'board_slug', CAST(NULL AS CHAR)
  )
)
WHERE channel_key = 'pinterest_colorfix_makeovers';
