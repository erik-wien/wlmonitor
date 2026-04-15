-- 003_wl_colors_repopulate.sql
--
-- Repair wl_colors: existing rows were inserted as UCS-2/UTF-16 bytes at
-- some point in the past and the German labels render as garbage. Wipe
-- and reseed with the canonical defaults. Admin UI lets labels be
-- edited after the fact.

TRUNCATE TABLE wl_colors;

INSERT INTO wl_colors (color, farbe, outline, full) VALUES
  ('default',   'Standard', 'btn-outline-default',   'btn-default'),
  ('primary',   'Blau',     'btn-outline-primary',   'btn-primary'),
  ('success',   'Grün',     'btn-outline-success',   'btn-success'),
  ('info',      'Türkis',   'btn-outline-info',      'btn-info'),
  ('warning',   'Orange',   'btn-outline-warning',   'btn-warning'),
  ('danger',    'Rot',      'btn-outline-danger',    'btn-danger'),
  ('secondary', 'Hellgrau', 'btn-outline-secondary', 'btn-secondary'),
  ('dark',      'Dunkel',   'btn-outline-dark',      'btn-dark');
