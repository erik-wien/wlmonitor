-- Add per-station line filter to favourites.
ALTER TABLE wl_favorites
    ADD COLUMN IF NOT EXISTS filter_json TEXT NULL DEFAULT NULL;
