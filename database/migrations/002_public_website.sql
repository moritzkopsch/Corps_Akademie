CREATE TABLE seminar_categories (
 id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0
);
INSERT INTO seminar_categories(slug,name,position) VALUES
 ('kommunikation','Kommunikation',1),('karriere','Karriere',2),('fuehrung','Führung',3),('studium','Studium',4),('corpsleben','Corpsleben',5);
CREATE TABLE referent_profiles (
 id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE REFERENCES users(id) ON DELETE SET NULL,
 slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, headline TEXT NOT NULL, corps TEXT NOT NULL DEFAULT '',
 biography TEXT NOT NULL, expertise_json TEXT NOT NULL CHECK(json_valid(expertise_json)),
 status TEXT NOT NULL CHECK(status IN ('draft','published','inactive','archived')),
 source_url TEXT NOT NULL DEFAULT '', row_version INTEGER NOT NULL DEFAULT 1,
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE seminars (
 id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL,
 category_id INTEGER NOT NULL REFERENCES seminar_categories(id) ON DELETE RESTRICT,
 summary TEXT NOT NULL, description TEXT NOT NULL, benefits_json TEXT NOT NULL CHECK(json_valid(benefits_json)),
 audience TEXT NOT NULL, duration TEXT NOT NULL, format TEXT NOT NULL DEFAULT 'Nach Vereinbarung',
 status TEXT NOT NULL CHECK(status IN ('draft','published','inactive','archived')),
 featured INTEGER NOT NULL DEFAULT 0 CHECK(featured IN (0,1)), source_url TEXT NOT NULL DEFAULT '',
 row_version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE INDEX seminars_public ON seminars(status,category_id);
CREATE TABLE seminar_referents (
 seminar_id INTEGER NOT NULL REFERENCES seminars(id) ON DELETE CASCADE,
 referent_id INTEGER NOT NULL REFERENCES referent_profiles(id) ON DELETE CASCADE,
 PRIMARY KEY(seminar_id,referent_id)
);
CREATE TABLE public_inquiries (
 id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE, submission_hash TEXT NOT NULL UNIQUE,
 kind TEXT NOT NULL CHECK(kind IN ('seminar','contact')),
 seminar_id INTEGER REFERENCES seminars(id) ON DELETE RESTRICT,
 email TEXT NOT NULL, email_key TEXT NOT NULL, details_ciphertext TEXT NOT NULL, key_id TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('unverified','received','in_progress','closed')),
 consent_version TEXT NOT NULL, verified_at INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 CHECK((kind='seminar' AND seminar_id IS NOT NULL) OR (kind='contact' AND seminar_id IS NULL))
);
CREATE INDEX inquiries_state ON public_inquiries(state,created_at);
CREATE TABLE newsletter_subscribers (
 id INTEGER PRIMARY KEY, email TEXT NOT NULL, email_key TEXT NOT NULL UNIQUE,
 state TEXT NOT NULL CHECK(state IN ('pending','active','unsubscribed')),
 consent_version TEXT NOT NULL, requested_at INTEGER NOT NULL, confirmed_at INTEGER, unsubscribed_at INTEGER
);
CREATE TABLE public_tokens (
 id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE,
 purpose TEXT NOT NULL CHECK(purpose IN ('inquiry_verify','newsletter_confirm','newsletter_unsubscribe')),
 inquiry_id INTEGER REFERENCES public_inquiries(id) ON DELETE CASCADE,
 subscriber_id INTEGER REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
 expires_at INTEGER NOT NULL, consumed_at INTEGER, revoked_at INTEGER, created_at INTEGER NOT NULL,
 CHECK((purpose='inquiry_verify' AND inquiry_id IS NOT NULL AND subscriber_id IS NULL)
 OR (purpose IN ('newsletter_confirm','newsletter_unsubscribe') AND inquiry_id IS NULL AND subscriber_id IS NOT NULL))
);
CREATE TABLE public_mail_outbox (
 id INTEGER PRIMARY KEY, event_key TEXT NOT NULL UNIQUE,
 token_id INTEGER REFERENCES public_tokens(id) ON DELETE CASCADE,
 recipient TEXT NOT NULL, payload_ciphertext TEXT, key_id TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('queued','sending','submitted','cancelled','blocked','ambiguous')),
 message_id TEXT NOT NULL UNIQUE, lease_token TEXT, lease_until INTEGER,
 created_at INTEGER NOT NULL, submitted_at INTEGER, last_error_redacted TEXT
);
CREATE INDEX public_mail_due ON public_mail_outbox(status,id);
INSERT INTO permissions(code,description) VALUES
 ('content.manage','Öffentliche Seminare und Referenten bearbeiten'),('inquiries.manage','Verifizierte öffentliche Anfragen bearbeiten');
INSERT INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code IN ('superadmin','admin') AND p.code IN ('content.manage','inquiries.manage');
