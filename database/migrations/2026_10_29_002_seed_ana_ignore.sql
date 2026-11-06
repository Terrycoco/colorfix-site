-- Use the established app_configs store. Preserve any already-authored list.
INSERT INTO app_configs (config_key, config_json)
VALUES ('ana_ignore', JSON_OBJECT('patterns', JSON_ARRAY(
    'ElevenlabsCrawler', 'Googlebot', 'Google-InspectionTool'
)))
ON DUPLICATE KEY UPDATE config_key = VALUES(config_key);
