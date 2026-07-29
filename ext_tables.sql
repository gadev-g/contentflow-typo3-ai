CREATE TABLE pages (
    tx_contentflow_focus_keywords text,
    tx_contentflow_schema_org text
);

CREATE TABLE tx_contentflow_migration_token (
    uid int unsigned auto_increment,
    pid int unsigned DEFAULT 0 NOT NULL,
    label varchar(120) DEFAULT '' NOT NULL,
    token_hash varchar(255) DEFAULT '' NOT NULL,
    token_prefix varchar(20) DEFAULT '' NOT NULL,
    created_at int unsigned DEFAULT 0 NOT NULL,
    last_used_at int unsigned DEFAULT 0 NOT NULL,
    revoked smallint unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY token_lookup (token_prefix, revoked)
);
