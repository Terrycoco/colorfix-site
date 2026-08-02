CREATE TABLE share_card_templates (
    share_card_template_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_key VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    svg_template_path VARCHAR(500) NOT NULL,
    required_fields_json JSON NULL,
    default_fields_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (share_card_template_id),
    UNIQUE KEY uq_share_card_templates_key (template_key),
    KEY idx_share_card_templates_active_sort (is_active, sort_order)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO share_card_templates
    (template_key, name, svg_template_path, required_fields_json, default_fields_json, is_active, sort_order)
VALUES
    (
        'concept',
        'Concept Share Card',
        'templates/share-cards/concept.svg',
        JSON_ARRAY('BRAND_LINE', 'TITLE', 'SUBTITLE', 'CTA_LABEL'),
        JSON_OBJECT(
            'BRAND_LINE', 'YOUR COLORFIX DESIGN CONCEPT',
            'TITLE', 'Design Concept',
            'SUBTITLE', '',
            'CTA_LABEL', 'Tap to See the Reveal',
            'FOOTER_NOTE', 'ColorFix by Terry'
        ),
        1,
        10
    ),
    (
        'palette',
        'Palette Share Card',
        'templates/share-cards/palette.svg',
        JSON_ARRAY('BRAND_LINE', 'TITLE', 'SUBTITLE', 'CTA_LABEL'),
        JSON_OBJECT(
            'BRAND_LINE', 'YOUR COLORFIX PALETTE',
            'TITLE', 'Paint Palette',
            'SUBTITLE', '',
            'CTA_LABEL', 'Tap to See Colors',
            'FOOTER_NOTE', 'ColorFix by Terry'
        ),
        1,
        20
    );
