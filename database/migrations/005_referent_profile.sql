ALTER TABLE referent_profiles ADD COLUMN image_path TEXT NOT NULL DEFAULT '';
ALTER TABLE referent_profiles ADD COLUMN linkedin_url TEXT NOT NULL DEFAULT '';
ALTER TABLE referent_profiles ADD COLUMN cv_path TEXT NOT NULL DEFAULT '';
ALTER TABLE referent_profiles ADD COLUMN corps_memberships JSONB NOT NULL DEFAULT '[]'::jsonb;
ALTER TABLE referent_profiles ADD COLUMN career_json JSONB NOT NULL DEFAULT '[]'::jsonb;
