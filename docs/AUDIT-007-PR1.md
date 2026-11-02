# AUDIT-007 PR-1: Environment and Privilege Inventory

Status: OPEN. This PR adds read-only inventory tooling only. It does not create
roles, change grants, apply migrations, or implement lifecycle functions.

## Repository findings

- `src/Database.php` loads connection settings from `RT_DB_CONFIG` or
  `.local/db.json`, but this PR does not read or print either file.
- `RT_DB_SCHEMA`, when set, is validated and applied as the connection
  `search_path`. When it is unset, PostgreSQL's ambient search path applies.
- The repository migrations create the application tables in the current schema;
  migrations 001-005 define `releases`, `release_items`, `audit_log`, and the
  release guard functions/triggers.
- `tools/migrate.php --status` and `--dry-run` are documented as read-only, but
  this inventory script does not invoke the migration tool.
- `tests/bootstrap.php` creates a random `rt_test_*` schema and applies all
  migrations. That test bootstrap is not used by this inventory because it
  writes to a database.

## Read-only inventory script

`tools/audit-007-pr1.sql` uses PostgreSQL catalogs and information-schema views.
It reports only metadata for the current database and `current_schema()`:

- database, current/session user, current schema, server version, search path;
- owners of application tables;
- table and column privileges for the release/audit tables and related tables;
- role flags and role memberships/inheritance;
- owner, `SECURITY DEFINER`, configuration and ACL metadata for `rt_*` and
  audit-related functions in the current schema;
- privileges granted to `PUBLIC` on the current schema's tables/functions;
- schema privileges for the current schema.

The script does not assume a schema named `app_schema` and contains no DDL/DML.
It must be run only after the operator independently verifies that the selected
database and schema are isolated. `current_schema()` is the scope boundary; the
output must record that value before any result is used.

## Safe execution procedure

1. Obtain a disposable PostgreSQL database or schema and its non-production
   connection details through the approved DBA channel. Do not paste credentials
   into a command, ticket, log, or report.
2. Connect with the intended inspection/runtime role, never a superuser, owner,
   or migration role when measuring runtime privileges.
3. Explicitly verify the database name, `current_user`, `session_user`, and
   `current_schema()` from the first result. Stop if any value is not the
   isolated target.
4. Run the script with `psql -X -v ON_ERROR_STOP=1 -f tools/audit-007-pr1.sql`.
   Keep output in a protected local location and redact role names if the
   environment treats them as sensitive. Never include passwords or tokens.
5. Do not use `-f database/schema.sql`, `tools/migrate.php`, `lab.sh migrate`,
   or any setup/seed command during this inventory.
6. Preserve the read-only output as evidence only after confirming it contains
   no secret values.

## Safety properties

- The SQL file contains `SELECT` statements and a `\set ON_ERROR_STOP` psql
  directive only.
- It does not call `CREATE`, `ALTER`, `DROP`, `GRANT`, `REVOKE`, `INSERT`,
  `UPDATE`, `DELETE`, `TRUNCATE`, or migration code.
- It does not read `.local/db.json`, `.env`, process environment values, or
  password/token columns.
- It cannot prove isolation by itself. Environment identity must be confirmed by
  the operator/DBA before execution.

## Current verification state

The repository environment currently has no `php` or `psql` executable in PATH.
No PostgreSQL connection was attempted, and no SQL result is claimed as PASS.
Static checks that do not require those tools may still be performed locally.

## PR-1 completion criteria

- The SQL script remains read-only and schema-agnostic.
- The operator can identify the isolated database/schema before execution.
- Runtime-role output is collected without superuser/owner privileges.
- Table/column/function/role/PUBLIC privilege evidence is retained.
- No migration, database object, permission, application file, or secret is
  changed or exposed.

## Stop conditions

Stop immediately if the database cannot be proven isolated, if the connection is
superuser/owner when runtime privileges are being measured, if any command would
write to the database, or if required PHP/PostgreSQL tooling is unavailable.

## Next DBA inputs

- An isolated database/schema endpoint and a disposable runtime inspection role.
- Confirmation of the schema selected by `RT_DB_SCHEMA` or the database's
  configured `search_path`.
- Effective-role and ownership expectations for the later `rt_schema_owner`,
  `rt_migrator`, and `rt_runtime` design.
- Approval to run this read-only script and a secure destination for its
  redacted output.
