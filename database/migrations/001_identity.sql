CREATE TABLE users (
 id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE, email TEXT NOT NULL, email_key TEXT NOT NULL UNIQUE,
 name TEXT NOT NULL, password_hash TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('active','disabled')),
 email_verified_at INTEGER, password_changed_at INTEGER NOT NULL, disabled_at INTEGER,
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, row_version INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, protected INTEGER NOT NULL CHECK(protected IN (0,1)));
CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL);
CREATE TABLE user_roles (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE RESTRICT, PRIMARY KEY(user_id,role_id));
CREATE INDEX user_roles_role ON user_roles(role_id);
CREATE TABLE role_permissions (role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE, permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE, PRIMARY KEY(role_id,permission_id));
CREATE INDEX role_permissions_permission ON role_permissions(permission_id);
CREATE TABLE sessions (
 id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, session_hash TEXT NOT NULL UNIQUE,
 payload_ciphertext TEXT NOT NULL, key_id TEXT NOT NULL, last_seen_at INTEGER NOT NULL, idle_expires_at INTEGER NOT NULL,
 absolute_expires_at INTEGER NOT NULL, revoked_at INTEGER, created_at INTEGER NOT NULL
);
CREATE INDEX sessions_user ON sessions(user_id,revoked_at);
CREATE INDEX sessions_expiry ON sessions(absolute_expires_at);
CREATE TABLE user_mfa_factors (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 kind TEXT NOT NULL CHECK(kind='totp'), secret_ciphertext TEXT NOT NULL, key_id TEXT NOT NULL,
 confirmed_at INTEGER NOT NULL, last_counter INTEGER NOT NULL, disabled_at INTEGER, created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX mfa_one_active ON user_mfa_factors(user_id) WHERE disabled_at IS NULL;
CREATE TABLE mfa_recovery_codes (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 code_hash TEXT NOT NULL UNIQUE, used_at INTEGER, created_at INTEGER NOT NULL
);
CREATE INDEX recovery_user ON mfa_recovery_codes(user_id);
CREATE TABLE email_verification_tokens (
 id INTEGER PRIMARY KEY, purpose TEXT NOT NULL CHECK(purpose IN ('bootstrap','password_reset','account_verification')),
 token_hash TEXT NOT NULL UNIQUE, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
 expires_at INTEGER NOT NULL, consumed_at INTEGER, revoked_at INTEGER, created_at INTEGER NOT NULL,
 CHECK((purpose='bootstrap' AND user_id IS NULL) OR (purpose IN ('password_reset','account_verification') AND user_id IS NOT NULL))
);
CREATE INDEX verification_user ON email_verification_tokens(user_id,purpose);
CREATE INDEX verification_expiry ON email_verification_tokens(expires_at);
CREATE TABLE rate_limit_buckets (
 id INTEGER PRIMARY KEY, scope TEXT NOT NULL, subject_hmac TEXT NOT NULL, window_start INTEGER NOT NULL,
 hits INTEGER NOT NULL CHECK(hits>0), expires_at INTEGER NOT NULL, UNIQUE(scope,subject_hmac,window_start)
);
CREATE INDEX limits_expiry ON rate_limit_buckets(expires_at);
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY, actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, action TEXT NOT NULL,
 entity_type TEXT NOT NULL, entity_public_id TEXT NOT NULL, changes_redacted TEXT NOT NULL CHECK(json_valid(changes_redacted)),
 correlation_id TEXT NOT NULL, occurred_at INTEGER NOT NULL
);
CREATE INDEX audit_time ON audit_logs(occurred_at);
CREATE TABLE security_events (
 id INTEGER PRIMARY KEY, actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, type TEXT NOT NULL,
 severity TEXT NOT NULL, subject_hmac TEXT, details_redacted TEXT NOT NULL CHECK(json_valid(details_redacted)),
 occurred_at INTEGER NOT NULL, retain_until INTEGER NOT NULL
);
CREATE INDEX security_time ON security_events(occurred_at);
CREATE TABLE notification_outbox (
 id INTEGER PRIMARY KEY, event_key TEXT NOT NULL UNIQUE, kind TEXT NOT NULL CHECK(kind IN ('password_reset','account_verification')),
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 token_id INTEGER NOT NULL REFERENCES email_verification_tokens(id) ON DELETE RESTRICT,
 recipient TEXT NOT NULL, secret_payload_ciphertext TEXT, key_id TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('queued','sending','submitted','cancelled','blocked','ambiguous')),
 available_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, lease_token TEXT, lease_until INTEGER,
 message_id TEXT NOT NULL UNIQUE, submitted_at INTEGER, last_error_redacted TEXT, created_at INTEGER NOT NULL
);
CREATE INDEX outbox_due ON notification_outbox(status,available_at);
CREATE INDEX outbox_lease ON notification_outbox(status,lease_until);
CREATE TABLE system_settings (id INTEGER PRIMARY KEY, key TEXT NOT NULL UNIQUE, value TEXT NOT NULL CHECK(json_valid(value)), setting_version INTEGER NOT NULL DEFAULT 1, changed_by INTEGER REFERENCES users(id) ON DELETE SET NULL, updated_at INTEGER NOT NULL);
CREATE TABLE system_events (id INTEGER PRIMARY KEY, type TEXT NOT NULL, severity TEXT NOT NULL, payload_redacted TEXT NOT NULL CHECK(json_valid(payload_redacted)), occurred_at INTEGER NOT NULL, correlation_id TEXT);
CREATE TABLE worker_heartbeats (id INTEGER PRIMARY KEY, worker_name TEXT NOT NULL UNIQUE, last_started_at INTEGER NOT NULL, last_success_at INTEGER, last_error_at INTEGER, release_version TEXT NOT NULL);
CREATE TABLE backup_history (id INTEGER PRIMARY KEY, backup_key TEXT NOT NULL UNIQUE, started_at INTEGER NOT NULL, completed_at INTEGER, status TEXT NOT NULL, manifest_hash TEXT, size_bytes INTEGER, encrypted INTEGER NOT NULL CHECK(encrypted IN (0,1)), restore_tested_at INTEGER);

INSERT INTO roles(code,label,protected) VALUES('superadmin','Superadministrator',1),('admin','Administrator',1),('instructor','Referent',1);
INSERT INTO permissions(code,description) VALUES
 ('admin.access','Administration öffnen'),('users.view','Benutzerübersicht lesen'),('users.manage','Benutzer und Rollen verwalten'),
 ('portal.access','Eigenes Referentenportal öffnen'),('account.manage','Eigenes Konto verwalten');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='superadmin';
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='admin' AND p.code IN ('admin.access','users.view','account.manage');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='instructor' AND p.code IN ('portal.access','account.manage');
