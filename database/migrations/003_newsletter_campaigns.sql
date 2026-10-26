ALTER TABLE newsletter_subscribers ADD COLUMN subscription_version INTEGER NOT NULL DEFAULT 1;
CREATE TABLE newsletter_campaigns (
 id INTEGER PRIMARY KEY, subject TEXT NOT NULL, body TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('draft','queued','completed','cancelled')),
 row_version INTEGER NOT NULL DEFAULT 1,
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, queued_at INTEGER
);
CREATE TABLE newsletter_deliveries (
 id INTEGER PRIMARY KEY, campaign_id INTEGER NOT NULL REFERENCES newsletter_campaigns(id),
 subscriber_id INTEGER NOT NULL REFERENCES newsletter_subscribers(id), subscription_version INTEGER NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('queued','sending','submitted','cancelled','ambiguous','blocked')),
 unsubscribe_hash TEXT UNIQUE,
 message_id TEXT NOT NULL UNIQUE, lease_token TEXT, lease_until INTEGER, submitted_at INTEGER, error_type TEXT,
 UNIQUE(campaign_id,subscriber_id)
);
CREATE INDEX newsletter_due ON newsletter_deliveries(state,id);
INSERT INTO permissions(code,description) VALUES ('newsletter.manage','Newsletter entwerfen und Versand freigeben');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('superadmin','admin') AND p.code='newsletter.manage';

CREATE TABLE newsletter_rate_limit (id INTEGER PRIMARY KEY CHECK(id=1), next_at INTEGER NOT NULL);
