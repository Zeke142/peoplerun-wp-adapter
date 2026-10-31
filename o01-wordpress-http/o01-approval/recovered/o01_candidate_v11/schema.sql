-- TEST DATABASE ONLY. NEVER execute against production.
-- Provision a disposable schema first; review every DDL statement.
CREATE TABLE pr_o01_revision_authority (
 project_id VARCHAR(64) NOT NULL, revision_id VARCHAR(64) NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 html_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 term ENUM('one_week','one_month','one_year') NOT NULL,
 status ENUM('awaiting_approval','withdrawn','approved') NOT NULL,
 PRIMARY KEY(project_id,revision_id)
) ENGINE=InnoDB;
CREATE TABLE pr_o01_approval_claims (
 project_id VARCHAR(64) NOT NULL, revision_id VARCHAR(64) NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 approval_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 html_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_id VARCHAR(128) NOT NULL,
 claimed_at DATETIME(6) NOT NULL,
 PRIMARY KEY(project_id,revision_id),
 UNIQUE KEY unique_receipt(receipt_id),
 CONSTRAINT fk_authority FOREIGN KEY(project_id,revision_id)
   REFERENCES pr_o01_revision_authority(project_id,revision_id)
) ENGINE=InnoDB;
