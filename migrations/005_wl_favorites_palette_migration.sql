-- Migration 005: rewrite wl_favorites.bclass to palette classes; drop wl_colors
-- DB: wlmonitor
-- Idempotent: WHERE bclass IN (...) only matches legacy values; safe to re-run.
-- Deployment order: run AFTER app code is deployed (code no longer queries wl_colors).

USE wlmonitor;

UPDATE wl_favorites SET bclass = CASE bclass
    WHEN 'btn-outline-default'   THEN 'btn-outline-color-neutral'
    WHEN 'btn-outline-primary'   THEN 'btn-outline-color-red'
    WHEN 'btn-outline-success'   THEN 'btn-outline-color-green'
    WHEN 'btn-outline-info'      THEN 'btn-outline-color-blue'
    WHEN 'btn-outline-warning'   THEN 'btn-outline-color-yellow'
    WHEN 'btn-outline-danger'    THEN 'btn-outline-color-red-dark'
    WHEN 'btn-outline-secondary' THEN 'btn-outline-color-grey-dark'
    WHEN 'btn-outline-dark'      THEN 'btn-outline-color-grey-dark'
    ELSE bclass
END
WHERE bclass IN (
    'btn-outline-default','btn-outline-primary','btn-outline-success',
    'btn-outline-info','btn-outline-warning','btn-outline-danger',
    'btn-outline-secondary','btn-outline-dark'
);

DROP TABLE IF EXISTS wl_colors;
