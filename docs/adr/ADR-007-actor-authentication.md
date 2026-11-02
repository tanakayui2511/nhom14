# ADR-007: Actor Authentication for Release Lifecycle Operations

- Status: Proposed
- Date: 2026-10-28
- Scope: `rt_release_mark_ready`, `rt_release_finalize`, and their audit records
- AUDIT-007: OPEN

## Context

The current application authenticates users in PHP. `public/login.php` verifies
the submitted password and stores the authenticated user ID in the PHP session.
The HTTP release controller obtains that session user and passes it to service
methods. `src/Database.php` connects with one configured database identity; the
repository has no separate database mechanism that independently proves which
human actor initiated a request. Release services also currently accept an actor
ID from their caller.

These facts are established from repository code already reviewed. No additional
database connection or runtime privilege inspection was performed for this ADR.

The security question is not whether PHP can identify a session user. It is
whether PostgreSQL can reject a caller using the same `rt_runtime` credential
that attempts to claim another actor.

## Options considered

### Option 1: Pass `p_actor_id`

The function accepts an actor ID as an argument and checks that user/project
membership.

- Spoofing risk: high. A caller with `EXECUTE` on the function can supply a
  different valid user ID unless another trusted channel binds the argument.
- Complexity: low.
- Fit with current PHP: high, because services already pass actor IDs.
- Decision: rejected as an authentication mechanism. It may remain a derived
  business parameter only after identity has been authenticated elsewhere.

### Option 2: Set actor in `current_setting`

PHP sets a custom GUC and the function reads it.

- Spoofing risk: high when `rt_runtime` can call `set_config`/`SET` itself. The
  value is caller-controlled and is not proof of the PHP session identity.
- Complexity: low to medium, with connection-pool reset requirements.
- Fit with current PHP: medium to high.
- Decision: rejected as the sole authentication mechanism. A GUC may carry
  already-authenticated context only when a trusted component sets and protects
  it; this repository does not currently provide that component.

### Option 3: Database role or connection identity per actor/group

The trusted application boundary obtains a database connection whose identity is
bound to the authenticated actor or to a narrowly defined actor group. Functions
derive identity from `SESSION_USER`/`CURRENT_USER` or from a DBA-controlled role
mapping, rather than from caller input.

- Spoofing risk: low against an unprivileged `rt_runtime` caller, provided the
  caller cannot `SET ROLE`, inherit the mapping role, or obtain another actor's
  credential.
- Complexity: high. It requires credential/connection management, pooling rules,
  role provisioning and revocation.
- Fit with current PHP: medium. The current single configured connection must be
  replaced or fronted by a trusted connection/role broker.
- Decision: acceptable only if the identity-to-role binding is implemented and
  independently tested by DBA/application infrastructure.

### Option 4: Trusted gateway with authenticated identity

PHP authenticates the session; a trusted gateway or connection broker verifies
that session and calls PostgreSQL through a protected identity channel. The
gateway prevents the web caller from choosing another actor. PostgreSQL receives
identity from that trusted boundary, not from a form field, `p_actor_id`, or a
GUC that `rt_runtime` can set freely.

- Spoofing risk: low against the web caller and `rt_runtime`, assuming the
  gateway credential/channel is protected and the runtime role cannot impersonate
  the gateway.
- Complexity: high, and it introduces infrastructure not present in the current
  repository.
- Fit with current PHP: medium. It preserves PHP session authentication but
  requires a gateway/adapter before lifecycle functions can be trusted.
- Decision: recommended target model.

## Decision

Adopt **Option 4, a trusted gateway/connection broker**, as the target actor
authentication model. The gateway must bind the PHP-authenticated session to an
identity channel that `rt_runtime` cannot rewrite. Where the gateway uses
database roles, Option 3 supplies the PostgreSQL-side identity primitive.

The following distinctions are mandatory:

1. PHP session identity is the application's authenticated user identity.
2. PostgreSQL identity is the identity conveyed by the trusted gateway or
   protected database role mapping.
3. `p_actor_id` is not authentication evidence.
4. A GUC set by `rt_runtime` is not authentication evidence.
5. With only an ordinary shared `rt_runtime` credential, PostgreSQL cannot prove
   which human session initiated the request.

This ADR does **not** claim that the gateway, role mapping, or protected identity
channel has been implemented. Those are dependencies and deployment gates for
the next PRs. If the application or runtime credential is fully compromised,
this model cannot preserve actor authenticity; it limits impersonation only when
the trusted boundary and its credentials remain protected.

## Lifecycle function contract

The lifecycle functions must not accept an actor ID as an authentication input:

```text
rt_release_mark_ready(p_release_id bigint, p_project_id bigint)
rt_release_finalize(p_release_id bigint, p_project_id bigint)
```

The function derives the actor from the trusted identity channel and then:

1. verifies the release belongs to the project;
2. verifies the authenticated actor is a current project member with
   `release_manager` or `admin` authority;
3. obtains locks and evaluates all gates in the same caller transaction;
4. performs lifecycle/snapshot writes only after validation;
5. appends an audit row using the derived actor identity;
6. raises a typed/domain error on not-found, forbidden, invalid-state, gate,
   conflict, or integrity failures.

PostgreSQL functions do not own transactions in the sense of issuing
`BEGIN`/`COMMIT`/`ROLLBACK`. The PHP service (or another approved caller) must
start the transaction, invoke the function, and commit only after the function
and audit write succeed. Any exception must be propagated to the caller, which
rolls back the transaction. An audit failure therefore rolls back the lifecycle
or snapshot change; a snapshot failure cannot leave a committed audit record.
The functions must not swallow errors or implement transaction control through
dynamic SQL.

The function implementation must also use a fixed, schema-qualified
`SECURITY DEFINER` design: owner is a non-login schema owner; `PUBLIC EXECUTE`
is revoked; `rt_runtime` receives only explicit execution; `search_path` is set
to the application schema with `pg_temp` not able to shadow unqualified names;
tables, sequences and helper functions are schema-qualified; dynamic SQL is
avoided or strictly identifier-whitelisted and quoted.

## Acceptance criteria

These are design/implementation tests, not tests run by this ADR. No runtime
test has been executed for this decision.

### Identity and authorization

- A caller using `rt_runtime` cannot select another actor by changing
  `p_actor_id`, a request field, or a self-set GUC.
- An actor outside the project is rejected.
- A project member without `release_manager`/`admin` authority is rejected.
- The audit actor equals the identity supplied by the trusted gateway/role
  mapping, not an arbitrary request parameter.
- `SET ROLE`, role inheritance and connection-pool reuse cannot switch one
  request's identity into another request's identity.

### Atomicity and lifecycle

- `mark_ready` and `finalize` run inside a caller-managed transaction.
- A forced gate, snapshot, lifecycle, or audit error rolls back all related
  writes.
- No partial snapshot or audit row remains after rollback.
- A concurrent request cannot finalize a stale gate result.
- Repeated/concurrent lifecycle calls produce one valid transition or a typed
  conflict, never two successful finalizations.

### Evidence classification

- The above are acceptance tests to be implemented in an isolated PostgreSQL
  schema/database.
- They are not evidence of passing behavior today.
- AUDIT-007 remains OPEN until the trusted identity boundary, database roles,
  functions, PHP integration, and isolated negative/concurrency tests exist and
  produce recorded results.

## Consequences

The recommended model adds infrastructure and operational work before DML can
be safely revoked from `rt_runtime`. Lifecycle function work must wait for the
identity boundary decision and its proof. Until then, the current PHP session
identity remains an application-level claim rather than independently verified
database actor evidence.

