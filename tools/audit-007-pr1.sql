-- AUDIT-007 PR-1: read-only PostgreSQL environment and privilege inventory.
-- Run only after independently confirming that the connected database/schema is isolated.
-- This script contains SELECTs only. It does not read application configuration.

\set ON_ERROR_STOP on

SELECT 'environment' AS section,
       current_database() AS database_name,
       current_user,
       session_user,
       current_schema() AS current_schema,
       current_setting('server_version') AS server_version;

SELECT 'search_path' AS section, current_setting('search_path') AS search_path;

WITH target_tables(table_name) AS (
    VALUES ('users'), ('projects'), ('project_members'), ('issues'),
           ('dependencies'), ('test_results'), ('releases'),
           ('release_items'), ('audit_log')
)
SELECT 'table_owner' AS section,
       n.nspname AS schema_name,
       c.relname AS table_name,
       pg_get_userbyid(c.relowner) AS owner,
       c.relkind
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN target_tables t ON t.table_name = c.relname
WHERE n.nspname = current_schema()
ORDER BY n.nspname, c.relname;

SELECT 'table_privilege' AS section,
       grantee,
       table_schema,
       table_name,
       privilege_type,
       is_grantable
FROM information_schema.table_privileges
WHERE table_schema = current_schema()
  AND table_name IN (
      'users', 'projects', 'project_members', 'issues', 'dependencies',
      'test_results', 'releases', 'release_items', 'audit_log'
  )
ORDER BY table_name, grantee, privilege_type;

SELECT 'column_privilege' AS section,
       grantee,
       table_schema,
       table_name,
       column_name,
       privilege_type,
       is_grantable
FROM information_schema.column_privileges
WHERE table_schema = current_schema()
  AND table_name IN ('releases', 'release_items', 'audit_log')
ORDER BY table_name, column_name, grantee, privilege_type;

SELECT 'role' AS section,
       r.rolname,
       r.rolcanlogin AS login,
       r.rolinherit AS inherit,
       r.rolsuper AS superuser,
       r.rolcreaterole AS createrole,
       r.rolcreatedb AS createdb,
       r.rolbypassrls AS bypassrls,
       r.rolreplication AS replication
FROM pg_roles r
ORDER BY r.rolname;

SELECT 'role_membership' AS section,
       member.rolname AS member_role,
       parent.rolname AS granted_role,
       m.admin_option,
       parent.rolinherit AS granted_role_inherit,
       to_jsonb(m)->>'inherit_option' AS membership_inherit_option,
       to_jsonb(m)->>'set_option' AS membership_set_option
FROM pg_auth_members m
JOIN pg_roles member ON member.oid = m.member
JOIN pg_roles parent ON parent.oid = m.roleid
ORDER BY member.rolname, parent.rolname;

SELECT 'function_owner_and_acl' AS section,
       n.nspname AS schema_name,
       p.oid::regprocedure AS function_name,
       pg_get_userbyid(p.proowner) AS owner,
       p.prosecdef AS security_definer,
       p.proconfig AS configuration,
       coalesce(pg_get_functiondef(p.oid), '') LIKE '%search_path%' AS definition_mentions_search_path,
       CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE grantee_role.rolname END AS grantee,
       acl.privilege_type,
       acl.is_grantable
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
CROSS JOIN LATERAL aclexplode(
    coalesce(p.proacl, acldefault('f', p.proowner))
) AS acl
LEFT JOIN pg_roles grantee_role ON grantee_role.oid = acl.grantee
WHERE n.nspname = current_schema()
  AND (left(p.proname, 3) = 'rt_' OR position('audit' IN p.proname) > 0)
ORDER BY p.proname, p.oid::regprocedure::text;

SELECT 'public_table_privilege' AS section,
       table_schema,
       table_name,
       privilege_type,
       is_grantable
FROM information_schema.table_privileges
WHERE grantee = 'PUBLIC'
  AND table_schema = current_schema()
  AND table_name IN ('releases', 'release_items', 'audit_log')
ORDER BY table_name, privilege_type;

SELECT 'public_function_privilege' AS section,
       routine_schema,
       routine_name,
       privilege_type,
       grantee
FROM information_schema.routine_privileges
WHERE grantee = 'PUBLIC'
  AND routine_schema = current_schema()
ORDER BY routine_name, privilege_type;

SELECT 'schema_privilege' AS section,
       n.nspname AS schema_name,
       CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE grantee_role.rolname END AS grantee,
       acl.privilege_type,
       acl.is_grantable
FROM pg_namespace n
CROSS JOIN LATERAL aclexplode(
    coalesce(n.nspacl, acldefault('n', n.nspowner))
) AS acl
LEFT JOIN pg_roles grantee_role ON grantee_role.oid = acl.grantee
WHERE n.nspname = current_schema()
ORDER BY grantee, acl.privilege_type;
