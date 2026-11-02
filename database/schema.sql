-- Legacy psql entry point. Prefer: php tools/migrate.php
-- Run this file from the project root so the relative include paths resolve.
\i database/migrations/001_initial_schema.sql
\i database/migrations/002_integrity_columns.sql
\i database/migrations/003_release_guards.sql
\i database/migrations/004_release_history_guards.sql
\i database/migrations/005_release_insert_guard.sql
\i database/migrations/006_runtime_guards.sql
