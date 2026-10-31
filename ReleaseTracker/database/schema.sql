-- Legacy psql entry point. Prefer: php tools/migrate.php
-- Run this file from the project root so the relative include paths resolve.
\i database/migrations/001_initial_schema.sql
\i database/migrations/002_integrity_columns.sql
\i database/migrations/003_release_guards.sql
