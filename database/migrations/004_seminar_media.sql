ALTER TABLE seminars ADD COLUMN image_path TEXT NOT NULL DEFAULT '';
ALTER TABLE seminars ADD COLUMN duration_options JSONB NOT NULL DEFAULT '[]'::jsonb;
CREATE INDEX seminars_featured ON seminars(featured);
