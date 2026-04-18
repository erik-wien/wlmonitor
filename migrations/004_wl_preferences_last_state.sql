-- Add cross-session monitor state to wl_preferences.
-- wl_favorites and wl_preferences are in the same DB (wlmonitor), so a real FK is valid per auth-rules §5(a).
-- ON DELETE SET NULL ensures last_fav_id is cleared automatically when a favourite is deleted.
USE wlmonitor;

ALTER TABLE wl_preferences
  ADD COLUMN IF NOT EXISTS last_fav_id INT          NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS last_diva   VARCHAR(16)  NULL DEFAULT NULL;

ALTER TABLE wl_preferences
  ADD CONSTRAINT fk_wlprefs_last_fav
  FOREIGN KEY (last_fav_id) REFERENCES wl_favorites(id) ON DELETE SET NULL;
