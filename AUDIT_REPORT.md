# AUDIT_REPORT — ReleaseTracker

**Ngày kiểm toán gốc:** 2026-10-28  
**Cập nhật Phase 2:** 2026-10-28 — đã sửa và kiểm thử AUDIT-004, 005, 006, 007, 009; AUDIT-008 còn chờ quyết định chính sách; AUDIT-011 đã bổ sung regression test (xem **Mục 10**).  
**Phạm vi:** Toàn bộ mã nguồn, cấu hình, migration, seed, test, tài liệu trong `D:\thu linh tinh\ReleaseTracker\ReleaseTracker`  
**Hình thức:** Audit tĩnh + kiểm chứng động trên schema tạm, **không sửa dữ liệu `public`**  
**Kết luận ngắn:** Sau Phase 2, **50/50 test service/DB** và **20/20 test HTTP** pass; các lỗi P1 đã được xử lý ở baseline, AUDIT-004/005/006/007/009 đã RESOLVED. AUDIT-008 chỉ đạt PARTIAL vì chính sách item non-blocker còn `open` chưa được chốt; hệ thống vẫn **chưa production-ready** do các P3 và giới hạn vận hành còn lại.

---

## 0. Tiếp tục Phase 2 — thay đổi và tái kiểm chứng hiện tại (2026-10-28)

> Phần bên dưới bổ sung cho kết quả lịch sử ở các mục sau; không thay thế kết quả đã ghi trước đó. Môi trường OpenCode hiện tại không có PHP và không có Bash/WSL hợp lệ, vì vậy không được diễn giải là đã chạy xanh lại.

### 0.1. Quyết định nghiệp vụ AUDIT-008

Người yêu cầu đã xác nhận: **mọi release item, kể cả non-blocker, phải ở `verified` hoặc `closed` trước khi chuyển `ready`/`released`**.

- **Nguyên nhân gốc:** gate cũ chỉ kiểm tra blocker và dependency, nên `open`/`in_progress`/`retest` non-blocker có thể qua `ready`.
- **Sửa:** `ReleaseService::check()` có condition `item_status`; `markReady()` và `release()` đã dùng cùng kết quả `check()`, nên từ chối giao dịch khi còn item chưa hoàn tất. UI không còn tuyên bố đây chỉ là cảnh báo.
- **File sửa trong lượt này:** `src/ReleaseService.php`, `public/releases.php`, `README.md`, `tests/run.php`, `tests/integration.php`.
- **Regression thêm/sửa:** service/DB `AUDIT-008` kiểm tra `open -> ValidationException`, trạng thái release vẫn `planned`, rồi `passed -> verified -> ready`; HTTP test kiểm tra điều kiện render, POST `ready` bị từ chối và database vẫn `planned`.

### 0.2. Trạng thái finding sau khi đọc mã

| Finding | Trạng thái mã | Bằng chứng tĩnh / test đã có | Trạng thái xác minh runtime hiện tại |
|---|---|---|---|
| AUDIT-001 | IMPLEMENTED | version form + service conflict; regression service/HTTP | BLOCKED |
| AUDIT-002 | IMPLEMENTED | recursive dependency closure + invalidation; regression service | BLOCKED |
| AUDIT-003 | IMPLEMENTED | constraint scoped theo `current_schema()`; migration idempotence test | BLOCKED |
| AUDIT-004 | IMPLEMENTED | released page chỉ render snapshot; HTTP content/authorization regression | BLOCKED |
| AUDIT-005 | IMPLEMENTED | migration `004`, transactional `project_name_snapshot`, legacy disclosure; notes service/HTTP regressions | BLOCKED |
| AUDIT-006 | IMPLEMENTED | migration `004` checks source and target release; direct SQL regression | BLOCKED |
| AUDIT-007 | PARTIAL | migration `004` rejects direct `planned -> released` and requires timestamps; however direct `planned -> ready -> released` with caller-supplied timestamps still bypasses service/snapshot/audit | OPEN (static) |
| AUDIT-008 | IMPLEMENTED (lượt này) | `item_status` gate + service/HTTP regression | BLOCKED |
| AUDIT-009 | IMPLEMENTED | `blockedForProject()` evaluates latest evidence/provenance/version; service regressions | BLOCKED |
| AUDIT-011 | PARTIAL | coverage exists for 001–009, including new 008; no executable result in current environment | BLOCKED |

Không finding nào được nâng trạng thái **RESOLVED** chỉ dựa trên đọc mã trong lượt này.

### 0.3. Lệnh thực tế và phạm vi database

| Lệnh | Kết quả thực tế |
|---|---|
| `git status --short`, `git diff --`, `git diff --cached` | BLOCKED: thư mục không phải Git repository; không khởi tạo Git. |
| `bash lab.sh check` | BLOCKED: WSL launcher không tìm thấy `/bin/bash`. |
| `php tests/run.php` | BLOCKED: `php` không có trong `PATH`. |
| `php tests/integration.php` | BLOCKED: `php` không có trong `PATH`. |
| `bash tests/backup-isolated.sh` | Không chạy: cùng blocker Bash/WSL. |

Không chạy migration, seed, SQL ghi, test ghi hoặc lệnh backup nào trên `public`; thực tế **không có kết nối database nào được mở trong lượt này**. `tests/bootstrap.php` được kiểm tra và được xác nhận thiết kế dùng schema ngẫu nhiên `rt_test_*` khi runtime sẵn sàng.

### 0.4. Migration, rủi ro và diff summary

- Migration P2 hiện diện: `004_release_history_guards.sql`, `005_release_insert_guard.sql`; không sửa migration cũ đã có thể được áp dụng.
- Rủi ro còn lại: migration 004–005 phải được áp dụng bằng quy trình triển khai được phê duyệt, **không phải** bởi lượt kiểm tra này; toàn bộ kết quả runtime vẫn cần chạy lại trong môi trường có PHP 8.1+ `pgsql`, PostgreSQL và Bash.
- **Lỗi tìm thấy khi rà soát migration 004 (AUDIT-007):** trigger chỉ cấm `NEW.status='released'` khi `OLD.status <> 'ready'` và khi timestamp thiếu. SQL trực tiếp vẫn có thể đặt release từ `planned` sang `ready`, sau đó sang `released` cùng `checked_at`/`released_at`, bỏ qua `ReleaseService::check()`, snapshot và audit. Cần thiết kế một đường DB-authorized duy nhất cho transition (ví dụ stored procedure có quyền riêng và thu hồi `UPDATE` lifecycle trực tiếp), hoặc một guard DB đủ khả năng chứng minh toàn bộ gate; đồng thời thêm SQL regression cho chuỗi bypass này. Không đánh dấu AUDIT-007 RESOLVED cho đến khi sửa và chạy test cô lập.

### 0.5. Rà soát bổ sung AUDIT-007 — boundary quyền database

- **Nguyên nhân gốc đầy đủ:** `004` coi `ready` và hai timestamp là đủ cho `released`; `005` chỉ chặn `INSERT` trạng thái ban đầu. Vì vậy hai lệnh `UPDATE` trực tiếp có thể bypass `ReleaseService::check()`, snapshot và audit.
- **Giới hạn kiến trúc hiện tại:** không có `GRANT`, `REVOKE`, role owner/runtime tách biệt hoặc `SECURITY DEFINER` trong migration. `tools/migrate.php` và application đều kết nối qua cùng `db()`/`PGUSER`. Nếu runtime role là owner (hoặc có quyền `UPDATE` lifecycle), `REVOKE` trên chính role owner không tạo boundary; custom GUC/session variable cũng không đáng tin vì caller cùng role có thể tự đặt.
- **Thiết kế cần được triển khai sau khi xác minh quyền DB:** owner `NOLOGIN` riêng sở hữu schema và function; runtime `LOGIN` chỉ có DML tối thiểu và **không** có `UPDATE(status, checked_at, released_at, project_name_snapshot)` trên `releases` hoặc quyền sửa snapshot/audit trực tiếp; lifecycle là `SECURITY DEFINER` function do owner sở hữu, với `SET search_path` cố định, `REVOKE EXECUTE FROM PUBLIC`, chỉ `GRANT EXECUTE` cho runtime. Function phải tự khóa project/release, kiểm tra role từ `project_members`, kiểm tra toàn bộ gate, ghi snapshot + project name + audit, rồi transition trong cùng transaction. `ReleaseService` gọi function thay vì `UPDATE` lifecycle trực tiếp.
- **Regression bắt buộc sau thiết kế:** kiểm tra `has_table_privilege`/`has_column_privilege` và `pg_get_userbyid(relowner)` cho runtime vs owner; SQL direct `planned -> ready`, direct `ready -> released` có timestamp, và chuỗi hai bước đều fail; service hợp lệ succeed; mọi fail giữ nguyên release, `release_items` và `audit_log`. Legacy fixture hiện tạo `ready/released` trực tiếp trong test phải được tạo trước khi guard mới áp dụng ở schema fixture riêng, không được giữ bypass trong suite chính.
- **Trạng thái:** AUDIT-007 là **OPEN**. Không tạo migration 006 trong lượt này vì chưa xác minh được role migration/runtime thực tế và không có PostgreSQL để kiểm thử role separation trên schema cô lập; tạo migration giả định tên/quyền role sẽ có nguy cơ khóa lifecycle hợp lệ hoặc cho cảm giác an toàn sai.
- Diff summary lượt này: thêm release condition `item_status`; loại bỏ UI copy nói item open chỉ là cảnh báo; chốt policy trong README; điều chỉnh fixture ready/release để dùng item hoàn tất; thêm regression service và siết regression HTTP cho AUDIT-008.
- Rà soát tĩnh sau thay đổi: tên test là duy nhất (`tests/run.php`: 51; `tests/integration.php`: 20); đã sửa README từ “50 test/cảnh báo item” thành “51 test/gate chặn item”. Không phát hiện biến thiếu, tham số SQL/lời gọi hàm sai hoặc fixture `ready`/`released` thành công với item chưa `verified`/`closed` trong các đoạn đã rà soát.

## 1. Tóm tắt điều hành và phạm vi

### 1.1. Kết quả nổi bật

| Hạng mục | Kết quả |
|---|---|
| Test service/DB | **50/50 PASS** (`TESTS=50 FAILED=0`) |
| Test HTTP | **20/20 PASS** (`INTEGRATION=20 FAILED=0`) |
| Backup/restore isolated | **59 PASS, 0 FAIL, 1 SKIP** (thiếu `age`/`age-keygen`) |
| Migration | 001–003 `APPLIED` trên `public`; **004–005 `PENDING`** (không áp dụng vào `public` trong audit) |
| Seed | 2 project, 40 issue, 15 dependency, 3 release, 5 user; **0 test_result, 0 release_item** |
| Legacy report | 5 legacy test, 0 trusted, 5 untrusted, 3 `verified_without_valid_test`, 4 `blocker_without_valid_test` |
| Phát hiện Phase 1 | **3 × P1, 7 × P2, 5 × P3** |
| Trạng thái Phase 2 | **004/005/006/007/009 RESOLVED**, **008 PARTIAL**, **011 PARTIAL** |
| Vi phạm core requirement | Yêu cầu đồ thị: **PASS**; đồng thời + snapshot: **PASS có guard DB**, còn policy AUDIT-008 |

### 1.2. Phạm vi đã đọc

Đã đọc toàn bộ các file sau (không bỏ sót file mã nguồn nào):

- Tài liệu/cấu hình: `README.md`, `.gitignore`, `lab.sh`, `LICENSE`.
- `src/`: `App.php`, `Database.php`, `DependencyService.php`, `Exceptions.php`, `IssueService.php`, `ProjectService.php`, `ReleaseNotes.php`, `ReleaseService.php`, `TestEvidence.php`, `Validation.php`.
- `public/`: `index.php`, `login.php`, `logout.php`, `projects.php`, `issues.php`, `dependencies.php`, `releases.php`, `release-notes.php`, `audit.php`.
- `database/`: `schema.sql`, `seed.sql`, `migrations/001_initial_schema.sql`, `002_integrity_columns.sql`, `003_release_guards.sql`.
- `tests/`: `bootstrap.php`, `helper.php`, `run.php`, `integration.php`, `worker.php`, `backup-isolated.sh`.
- `tools/`: `migrate.php`, `seed.php`, `db-check.php`, `db-config.php`, `legacy-report.php`, `sql.php`, `backup.sh`.
- Assets: `public/assets/css/app.css`, `public/assets/css/tokens.css`.

Không có `composer.json`; dự án dùng `require_once` thủ công, không có autoloader, không có dependency bên thứ ba.

### 1.3. Ngoài phạm vi

- Không kiểm thử xâm nhập (penetration test) thực sự.
- Không kiểm thử trên trình duyệt thật, screen reader, thiết bị di động.
- Không đo tải/performance.
- Không có quyền truy cập Git history (thư mục không phải git repo).
- Không chạy migration trên database production; chỉ đọc legacy report.

---

## 2. Phương pháp và môi trường kiểm toán

### 2.1. Phương pháp

1. **Đọc tĩnh** toàn bộ mã nguồn, đối chiếu README với hành vi thực tế.
2. **Chạy test hiện có** trong schema tạm `rt_test_*`.
3. **Viết script tái hiện độc lập** trong `C:\Users\Admin\AppData\Local\Temp\opencode\` để kiểm chứng từng nghi vấn, chạy trong schema `rt_audit_probe_*`.
4. **Dọn dẹp**: drop toàn bộ schema tạm; kiểm tra `residual_rt_schemas=0`.
5. **Không sửa** bất kỳ file nào trong dự án. Chỉ tạo `AUDIT_REPORT.md` (báo cáo) và các script tạm ngoài dự án.

### 2.2. Môi trường

| Thành phần | Giá trị |
|---|---|
| Host | Windows, working dir `D:\thu linh tinh\ReleaseTracker\ReleaseTracker` |
| Runtime | WSL Ubuntu, PHP **8.5.4** với `pgsql`, `pdo_pgsql` |
| Database | PostgreSQL **18.6**, `127.0.0.1:5432` |
| DB đích | `communitypass_lab_v5`, schema `public` |
| Role ứng dụng | `communitypass_lab_v5` (không có `CREATEDB`, không superuser) |
| Git | **Không phải git repository** (`git status` → `fatal: not a git repository`) |
| Docker | CLI có, daemon không khởi động được (permission/500) → dùng WSL |

### 2.3. Lệnh thực tế đã chạy

```bash
# Mẫu chung
wsl -d Ubuntu -- bash -lc "cd '/mnt/d/thu linh tinh/ReleaseTracker/ReleaseTracker' && <lệnh>"

bash lab.sh check
php -d session.save_path=/tmp -d session.name=RTAUDIT tests/run.php
php -d session.save_path=/tmp -d session.name=RTAUDIT tests/integration.php
bash tests/backup-isolated.sh
php tools/migrate.php --status
php tools/legacy-report.php --json
```

Kết quả thực tế được ghi lại ở Mục 9.1. Không có kết quả nào trong báo cáo này là suy đoán hoặc bịa đặt.

### 2.4. Nguyên tắc bằng chứng

- **CONFIRMED**: đã đọc code + tái hiện động, quan sát đúng hành vi.
- **LIKELY**: đọc code thấy rõ nhưng chưa tái hiện đầy đủ do giới hạn môi trường.
- **UNKNOWN**: chưa đủ bằng chứng.
- Chỉ ghi **PASS** khi đã chạy test hoặc tái hiện thành công.

---

## 3. Kiến trúc hệ thống

### 3.1. Tổng quan

Ứng dụng PHP thuần, mô hình 3 lớp đơn giản:

```text
public/*.php        Controller + View: session, CSRF, phân quyền, validate, render HTML
src/*Service.php    Nghiệp vụ: issue, dependency, release, project, release notes
src/Database.php    Hạ tầng: kết nối, query tham số, transaction/savepoint, project lock
database/*          Schema, migration, seed
tests/*             Test service/DB + HTTP integration
tools/*             CLI migrate, seed, backup, legacy report
```

Luồng ghi điển hình (đúng như README Mục “Kiến trúc”):

1. Controller kiểm tra CSRF, quyền, validate input.
2. Service mở transaction.
3. `projectLock()` khóa row `projects` để tuần tự hóa theo dự án.
4. Đọc bản ghi cần thiết bằng `FOR UPDATE`.
5. Kiểm tra nghiệp vụ.
6. Ghi dữ liệu + audit trong cùng transaction.
7. Commit.

### 3.2. Điểm mạnh kiến trúc

- **Tách lớp tương đối rõ**: service không render HTML; controller không tự viết SQL phức tạp.
- **Transaction nhất quán**: hầu hết thao tác ghi đều đi qua `transaction()`; lỗi nghiệp vụ ném exception và rollback.
- **Khóa theo dự án** (`projectLock`) là quyết định tốt để tránh race giữa issue/test/dependency/release.
- **Query tham số hóa** gần như toàn bộ; không thấy nối chuỗi input người dùng vào SQL.
- **Audit trong cùng transaction** với thao tác nghiệp vụ, tránh audit “thành công giả”.
- **Test isolation bằng schema** phù hợp với role không có `CREATEDB`.

### 3.3. Rủi ro kiến trúc

| Rủi ro | Mô tả | Mức |
|---|---|---|
| Không có autoloader | `require_once` thủ công, dễ thiếu file khi mở rộng | Thấp |
| Logic điều kiện phát hành tập trung một hàm dài | `ReleaseService::check()` 206 dòng, khó test theo nhánh | Trung bình |
| Read model và write model lệch nhau | `check()` chỉ đọc dependency trực tiếp; `invalidateReadyReleases()` chỉ reset dependent trực tiếp | **Cao** |
| UI gọi `check()` cho cả release đã chốt | Trộn trạng thái lịch sử với đánh giá live | Trung bình |
| Migration kiểm tra constraint theo tên toàn cục | Không scope schema/table → silently skip | **Cao** |
| Trigger bảo vệ snapshot chỉ là “lớp 2” nhưng có lỗ hổng | Move item và direct transition bypass | **Cao** |
| Không có CI/static analysis | Không phát hiện regression tự động | Trung bình |

### 3.4. Đánh giá kiến trúc

Kiến trúc **phù hợp với quy mô lab**, dễ đọc, dễ chạy. Tuy nhiên, các bất biến quan trọng nhất (dependency xuyên suốt, snapshot bất biến, version khi ghi test) đang được thực thi **không nhất quán giữa service, trigger và UI**, tạo ra khoảng trống đúng ở nơi yêu cầu kỹ thuật core đòi hỏi.

---

## 4. Cơ sở dữ liệu, migration và seed

### 4.1. Schema

Các bảng chính:

| Bảng | Vai trò |
|---|---|
| `users` | Tài khoản, `password_hash` |
| `projects` | Dự án, là đơn vị khóa tuần tự |
| `project_members` | Vai trò theo dự án: `developer`, `tester`, `release_manager`, `admin` |
| `issues` | Công việc, `version` cho optimistic locking, `priority` gồm `blocker` |
| `dependencies` | Cạnh `issue_id -> depends_on_id`, có `issue_project_id`/`depends_on_project_id` |
| `comments` | Bình luận |
| `test_results` | Kết quả test, `issue_version`, `tester_id` |
| `releases` | Phiên bản: `planned -> ready -> released` |
| `release_items` | Danh sách item + snapshot fields |
| `audit_log` | Audit nghiệp vụ |

### 4.2. Migration

- `001_initial_schema.sql`: tạo bảng nền, dùng `IF NOT EXISTS`.
- `002_integrity_columns.sql`: thêm `project_id`, `issue_version`, snapshot metadata, composite FK/unique, index.
- `003_release_guards.sql`: trigger chặn sửa/xóa release `released` và `release_items` sau chốt.

`php tools/migrate.php --status` xác nhận cả 3 migration đã `APPLIED` trên `public`; lệnh này chỉ đọc, không tạo `schema_migrations`.

### 4.3. Seed — kiểm chứng số lượng

Chạy trong schema mới `rt_test_*`:

```json
{"users":"5","projects":"2","issues":"40","dependencies":"15","releases":"3","release_items":"0","test_results":"0"}
```

**Kết luận seed:** đúng số lượng README (2 project, 40 issue, 15 dependency, 3 release, 5 user), nhưng **3 release đều rỗng** và **không có test_result**. Seed không tự nó chứng minh được luồng “blocker → retest → ready → release → snapshot”; phải thao tác tay hoặc dùng test.

### 4.4. Phát hiện liên quan

- **AUDIT-003 (P1)**: migration 002 kiểm tra constraint theo `conname` toàn cục, không scope schema/table → constraint bị bỏ qua trong schema mới.
- **AUDIT-006 (P2)**: trigger `release_items` dùng `COALESCE(NEW.release_id, OLD.release_id)` → move item ra khỏi release đã chốt được phép.
- **AUDIT-007 (P2)**: trigger `releases` chỉ chặn `OLD.status='released'` → `planned -> released` trực tiếp được phép.
- **AUDIT-015 (P3)**: seed không có release_items/test_results nên không hỗ trợ demo end-to-end chỉ bằng seed.

### 4.5. Chi tiết AUDIT-003 — migration constraint scoping

**Vị trí:** `database/migrations/002_integrity_columns.sql`, các dòng 7, 22, 29, 63, 71, 79, 87, 118, 126, 134.

**Hiện tại:**

```sql
IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_issues_id_project') THEN
  ALTER TABLE issues ADD CONSTRAINT uq_issues_id_project UNIQUE (id, project_id);
END IF;
```

`pg_constraint` chứa constraint của **mọi schema**. Khi chạy migration trong schema `rt_test_*`, nếu `public` đã có constraint cùng tên, điều kiện `NOT EXISTS` trả về false và migration **bỏ qua** việc tạo constraint cho schema test.

**Kỳ vọng:** kiểm tra phải scope theo schema và bảng, ví dụ:

```sql
IF NOT EXISTS (
  SELECT 1 FROM pg_constraint c
  JOIN pg_class t ON t.oid = c.conrelid
  JOIN pg_namespace n ON n.oid = t.relnamespace
  WHERE c.conname = 'uq_issues_id_project'
    AND t.relname = 'issues'
    AND n.nspname = current_schema()
) THEN ...
```

**Tái hiện:** probe schema `rt_audit_probe_*` có **103 constraint** nhưng thiếu toàn bộ constraint do 002 thêm: `uq_issues_id_project`, `fk_test_results_issue_project`, v.v.

**Tác động:**

- Test chạy trong schema thiếu composite FK/unique → **test không kiểm tra được integrity thật**.
- Một deployment vào schema không phải `public`, hoặc sau khi rename/restore, có thể thiếu constraint mà không báo lỗi.
- README tuyên bố “khóa ngoại tổ hợp cùng dự án” nhưng schema test không có.

**Cách sửa:** scope `pg_constraint` theo `conrelid`/`connamespace`, hoặc dùng `ALTER TABLE ... ADD CONSTRAINT` và bắt `duplicate_object`, hoặc kiểm tra bằng `to_regclass`.

**Test bắt buộc:** tạo schema mới, chạy migration, query `information_schema.table_constraints`/`pg_constraint` và assert đủ danh sách constraint trong **đúng schema đó**; chạy lại migration lần 2 để xác nhận idempotent.

---

## 5. Nghiệp vụ, thuật toán đồ thị và điều kiện phát hành

### 5.1. Thuật toán đồ thị tự cài đặt

**Vị trí:** `src/DependencyService.php`

| Hàm | Dòng | Thuật toán | Đánh giá |
|---|---|---|---|
| `findPath()` | 9–29 | DFS lặp bằng stack, lưu `seen` | Đúng, trả path hoặc `null`; không đảm bảo shortest path nhưng không cần |
| `findCycle()` | 31–65 | DFS đệ quy, màu 0/1/2, stack | Đúng, phát hiện back-edge và trả chu trình khép kín |
| `adjacency()` | 67–... | Query toàn bộ cạnh trong project | Đúng, có scope project |
| `projectCycle()` | — | Gọi `findCycle(adjacency())` | Đúng |

Đây là **cài đặt tay**, không dùng thư viện đồ thị. Yêu cầu “tự cài đặt thuật toán” được đáp ứng.

**Điểm cần lưu ý:** `findCycle()` đệ quy có thể gặp giới hạn stack nếu đồ thị rất sâu; với 40 issue của lab thì không đáng lo.

### 5.2. Kiểm tra khi thêm dependency

`DependencyService::add()` thực hiện:

- Từ chối self-dependency.
- Từ chối dependency chéo project.
- Từ chối trùng cạnh.
- Thêm cạnh tạm, chạy `findCycle`, nếu có chu trình thì rollback và ném `ConflictException` kèm nhãn `A -> B -> C -> A`.
- Ghi audit trong cùng transaction.

**Kết quả:** TEST 1 và TEST 1b pass; đã kiểm chứng động.

### 5.3. Điều kiện phát hành

`ReleaseService::conditions()` công bố 7 nhóm điều kiện:

| Code | Nội dung | Nguồn kiểm tra |
|---|---|---|
| `items` | Release phải có ít nhất 1 item | `check()` dòng 198–200 |
| `project_items` | Mọi item thuộc đúng project | dòng 201–206 |
| `blockers` | Blocker phải có test `passed` đúng version + provenance | dòng 222–232 |
| `dependencies` | Dependency trực tiếp phải `verified`/`closed` và test hợp lệ | dòng 233–278 |
| `no_cycles` | Project không có chu trình | dòng 280–284 |
| `snapshot` | Snapshot item hợp lệ | dòng 218–221 |
| `role` | Người thao tác phải là release_manager/admin | dòng 285–287 |

### 5.4. Lỗ hổng dependency xuyên suốt — AUDIT-002

**Vị trí:** `src/ReleaseService.php` dòng 153–183 (query dependency) và 233–278 (vòng lặp).

**Hiện tại:** `$dependencies` lấy **tất cả cạnh trực tiếp** trong project, nhóm theo `issue_id`. Vòng lặp chỉ duyệt `$depsByIssue[$issueId]` — tức **dependency trực tiếp của item**. Không có BFS/DFS để kiểm tra dependency bắc cầu.

Ví dụ:

```text
A (open)  <-  B (verified)  <-  C (verified)
```

Release chứa C:

- `check()` chỉ thấy C phụ thuộc B; B `verified`, test hợp lệ → **0 problem**.
- A đang `open`, nhưng A không phải dependency trực tiếp của C → **không bị phát hiện**.

**Tái hiện động:**

1. Tạo A `open`, B `verified` depends on A, C `verified` depends on B.
2. Tạo release chứa C → `check` 0 problem, `markReady` **SUCCESS**.
3. Ghi test `failed` cho A.
4. Release cũ **vẫn `ready`**; tạo release mới chứa C → `markReady` **SUCCESS**, `check` 0 problem.

**Kỳ vọng:** nếu một item phụ thuộc bắc cầu vào issue chưa đạt, release phải bị chặn; khi A đổi trạng thái, mọi release chứa dependent bắc cầu phải bị reset khỏi `ready`.

**Tác động:** release có thể được chốt trong khi chuỗi phụ thuộc thực tế chưa hoàn thành. Đây là **sai lệch nghiệp vụ P1**.

**Cách sửa:**

- Xây dựng closure của dependency cho từng item (BFS/DFS) và kiểm tra toàn bộ node trong closure.
- Hoặc query đệ quy bằng `WITH RECURSIVE`.
- `invalidateReadyReleases()` phải lan truyền theo closure, không chỉ `depends_on_id IN (ids)` trực tiếp.

**Test bắt buộc:** A open, B verified → A, C verified → B; release chứa C phải bị chặn; sau khi A failed, release đang `ready` phải bị reset; release mới chứa C phải `markReady` thất bại.

### 5.5. Invalidation chỉ một tầng — cùng AUDIT-002

**Vị trí:** `src/DependencyService.php` dòng 256–292.

**Hiện tại:** `invalidateReadyReleases()` reset các release `ready` có item:

- chính issue vừa đổi, hoặc
- item có **cạnh trực tiếp** `depends_on_id IN (ids)`.

**Kỳ vọng:** reset mọi release chứa item phụ thuộc **bắc cầu** vào issue vừa đổi.

**Tác động:** release đã `ready` có thể tiếp tục `ready` dù điều kiện đã mất hiệu lực; người dùng có thể chốt ngay mà không cần check lại (dù `release()` có gọi `check()` — xem AUDIT-002, `check()` cũng không thấy).

**Cách sửa:** dùng recursive CTE để lấy toàn bộ descendant của `ids` trong project, rồi reset release chứa bất kỳ descendant nào.

**Test bắt buộc:** như trên, thêm assert `releases.status='planned'` sau khi A failed.

### 5.6. Open non-blocker item — AUDIT-008

**Vị trí:** `src/ReleaseService.php` dòng 201–232.

**Hiện tại:** chỉ item priority `blocker` được kiểm tra test. Item `low/medium/high` ở trạng thái `open`/`in_progress`/`retest` **không sinh problem** nếu không phải blocker.

**Tái hiện:** release chứa một item `open`, priority `medium` → `open_item_check_problems=0`, `markReady=SUCCESS`.

**Kỳ vọng:** phụ thuộc chính sách sản phẩm. Nếu release chỉ được chứa item đã `verified`/`closed`, cần thêm điều kiện; nếu cho phép ship item chưa xong, phải ghi rõ trong tài liệu và UI.

**Tác động:** release có thể chốt với công việc chưa hoàn thành. Với lab, đây có thể là chủ ý; nhưng README không nói rõ, nên **hành vi CONFIRMED, mức độ là P2 policy gap**.

**Cách sửa:** quyết định policy; nếu cần chặn, thêm condition `item_status`; nếu không, thêm cảnh báo UI “release còn item chưa verified”.

**Test bắt buộc:** test theo policy đã chọn; nếu chặn, assert `markReady` ném `ValidationException`.

### 5.7. Danh sách blocked không nhất quán — AUDIT-009

**Vị trí:** `src/DependencyService.php` dòng 241–254, đặc biệt dòng 250.

**Hiện tại:**

```sql
AND b.status NOT IN ('verified','closed')
```

→ blocker đã `closed` nhưng test mới nhất `failed` **không xuất hiện** trong danh sách blocked.

**Kỳ vọng:** `ReleaseService::check()` (dòng 269–270) **có** coi trường hợp này là problem:

```php
} elseif ($latestResult !== null && (string)$latestResult !== 'passed') {
    $reason = 'đã closed nhưng kết quả kiểm thử mới nhất là ' . (string)$latestResult;
}
```

**Tái hiện:** `blocked_list_rows=0` nhưng `release_check_problems=1`.

**Tác động:** dashboard “blocked” báo không có blocker, trong khi release check lại chặn → người dùng mất tin cậy, khó debug.

**Cách sửa:** align query với logic `check()`: include `closed` nếu latest test không `passed`/không trust/stale.

**Test bắt buộc:** closed blocker + latest `failed` phải xuất hiện trong `blockedForProject()` và tạo problem trong `check()`.

---

## 6. Đồng thời, giao dịch và bất biến snapshot

### 6.1. Cơ chế hiện có

| Cơ chế | Vị trí | Đánh giá |
|---|---|---|
| `projectLock()` | `src/Database.php` | Khóa row `projects` bằng `FOR UPDATE`, tuần tự hóa theo project |
| `transaction()` | `src/Database.php` | BEGIN/COMMIT/ROLLBACK, savepoint |
| Optimistic locking issue | `src/IssueService.php` `update()` | `UPDATE ... WHERE version=$n`, affected rows != 1 → `ConflictException` |
| `FOR UPDATE` bản ghi | `issueForUpdate`, `releaseForUpdate` | Đọc bản ghi trong transaction |
| Snapshot khi release | `ReleaseService::release()` dòng 385–427 | Ghi `snapshot_*` vào `release_items` |
| Trigger bảo vệ | `003_release_guards.sql` | Chặn sửa/xóa release `released` và item |

### 6.2. Điểm mạnh

- Hai process cùng sửa issue: một thắng, một nhận `XUNG ĐỘT`; dữ liệu cuối là của client thắng (TEST 3a/3b/3c pass).
- Release chỉ chốt được từ `ready`; `release()` khóa release + project, kiểm tra lại `check()`, snapshot từng item, rồi update trạng thái; nếu affected rows != 1 thì conflict.
- Audit ghi trong cùng transaction.
- Trigger là lớp bảo vệ thứ hai cho đường đi bình thường.

### 6.3. Lỗ hổng stale test form — AUDIT-001

**Vị trí:**

- `src/IssueService.php` `recordTest()` dòng 229–299, đặc biệt 255–260 và 279–289.
- `public/issues.php` form test dòng 285–299: **không có** hidden input `version`.
- So sánh: form update issue dòng 238 **có** hidden `version`.

**Hiện tại:** `recordTest()` nhận `issueId`, `result`, `notes`; **không nhận base version từ client**. Nó đọc version hiện tại bằng `issueForUpdate()` (dòng 255), rồi tự tính `newVersion = oldVersion + 1` (dòng 259–260). Vì vậy form được load ở version cũ vẫn được chấp nhận, và test được ghi vào version **mới nhất**, không phải version người dùng nhìn thấy.

**Tái hiện:**

1. Issue ở v2 (đã có một test passed).
2. Developer sửa issue → v3.
3. Tester dùng form cũ (load từ v2), submit `passed`.
4. Hệ thống chấp nhận, issue thành **v4 verified**; `check` 0 problem; `markReady` **SUCCESS**.

**Kỳ vọng:** form phải gửi `version` (hoặc `issue_version`) mà tester đã thấy; `recordTest()` phải so sánh với version hiện tại và ném `ConflictException` nếu khác, tương tự `update()`.

**Tác động:** tester có thể “xác nhận” một phiên bản issue mà mình chưa thực sự kiểm thử. Đây là **P1** vì phá vỡ ý nghĩa của test evidence và điều kiện release.

**Cách sửa:**

- Thêm `<input type="hidden" name="version" value="<?= (int)$detail['version'] ?>">` vào form test.
- `recordTest()` nhận `$expectedVersion`, kiểm tra `(int)$issue['version'] === $expectedVersion`, nếu không ném `ConflictException` với thông báo rõ.
- Có thể dùng `UPDATE ... WHERE version=$expected` thay vì đọc rồi update.

**Test bắt buộc:** load form ở v2, update issue lên v3, submit test cũ → phải nhận `ConflictException`; issue không đổi status/version; không có test_result mới.

### 6.4. Snapshot và release notes — AUDIT-005

**Vị trí:** `src/ReleaseNotes.php` dòng 11–15, 27.

**Hiện tại:**

```php
'SELECT r.*,p.name AS project_name
 FROM releases r JOIN projects p ON p.id=r.project_id
 WHERE r.id=$1'
...
$lines[] = '# Ghi chú phát hành ' . $release['version_name'] . ' — ' . $release['project_name'];
```

Release notes đọc **tên project hiện tại**, không phải tên tại thời điểm release.

**Tái hiện:** release `AuditRename ...`; đổi tên project thành `AuditRename Renamed`; tải lại notes → tiêu đề đổi theo; so sánh notes trước/sau = **NO**.

**Kỳ vọng:** snapshot phải bất biến, gồm cả tên project tại thời điểm chốt.

**Tác động:** lịch sử release bị thay đổi sau khi đã công bố; vi phạm yêu cầu “immutable released snapshot” ở mức metadata.

**Cách sửa:** thêm cột `project_name` vào `releases` (hoặc `release_items`) và ghi tại thời điểm release; `ReleaseNotes` đọc từ snapshot. Nếu không muốn đổi schema, bỏ tên project khỏi tiêu đề notes.

**Test bắt buộc:** release → rename project → `ReleaseNotes::markdown()` phải giống hệt trước rename (byte-for-byte).

### 6.5. Trigger move item — AUDIT-006

**Vị trí:** `database/migrations/003_release_guards.sql` dòng 4–20, đặc biệt dòng 9.

**Hiện tại:**

```sql
SELECT status INTO rel_status FROM releases
WHERE id = COALESCE(NEW.release_id, OLD.release_id);
```

Khi `UPDATE release_items SET release_id = <planned_release>` từ một item thuộc release `released`, `NEW.release_id` là release planned → `rel_status='planned'` → trigger cho phép.

**Tái hiện:** release đã chốt có 1 item; `UPDATE release_items SET release_id=<planned> WHERE ...` → `move_result=ALLOWED`; số item của release đã chốt giảm 1 → 0.

**Kỳ vọng:** nếu **một trong hai** release (OLD hoặc NEW) đang `released`, phải chặn; đồng thời chặn đổi `release_id`, `project_id`, `issue_id` sau khi chốt.

**Tác động:** snapshot của release đã công bố có thể bị rút ruột bằng SQL trực tiếp (dù service không làm vậy). Lớp bảo vệ thứ hai không đáng tin.

**Cách sửa:**

```sql
IF TG_OP = 'UPDATE' AND (
  OLD.release_id IS DISTINCT FROM NEW.release_id OR
  OLD.project_id IS DISTINCT FROM NEW.project_id OR
  OLD.issue_id IS DISTINCT FROM NEW.issue_id
) THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_KEY_IMMUTABLE';
END IF;
```

và kiểm tra status của **cả** OLD và NEW release.

**Test bắt buộc:** release → move item sang planned → phải ném exception; item vẫn thuộc release cũ.

### 6.6. Trigger direct transition — AUDIT-007

**Vị trí:** `database/migrations/003_release_guards.sql` dòng 28–37, đặc biệt 30–35.

**Hiện tại:** trigger chỉ chặn nếu `OLD.status='released'`. Vì vậy `UPDATE releases SET status='released' WHERE status='planned'` **được phép**.

**Tái hiện:** `direct_planned_to_released=ALLOWED`; `checked_at=NULL`, `released_at=NULL`.

**Kỳ vọng:** DB-level transition matrix chỉ cho `ready -> released`; `planned -> released` phải bị chặn. Nếu cho phép, ít nhất phải set `checked_at`/`released_at`.

**Tác động:** bỏ qua toàn bộ `check()`, snapshot và audit; release “released” nhưng không có snapshot, không có timestamp.

**Cách sửa:** trigger kiểm tra `NEW.status='released' AND OLD.status<>'ready'` → raise; thêm ràng buộc `released => released_at IS NOT NULL`.

**Test bắt buộc:** `UPDATE releases SET status='released'` từ `planned` phải ném exception; từ `ready` phải thành công và có timestamp.

### 6.7. Released release vẫn bị check live — AUDIT-004

**Vị trí:** `public/releases.php` dòng 98–113.

**Hiện tại:** với mọi release được chọn (kể cả `released`), trang gọi `ReleaseService::check($connection, $selected, $projectId, $role)` và render kết quả như một đánh giá hiện tại.

**Tái hiện:**

- Release đã `released`; đăng nhập release_manager → `check` 0 problem.
- Đăng nhập developer → 1 problem `role`.
- Sửa issue nguồn → 1 problem `stale-test` dù release vẫn `released`.

**Kỳ vọng:** release đã chốt phải hiển thị trạng thái lịch sử/snapshot, không bị đánh giá lại theo quyền hiện tại hay dữ liệu hiện tại.

**Tác động:** UI có thể hiển thị “chưa đủ điều kiện” cho một release đã phát hành, gây hiểu nhầm và làm suy yếu thông điệp “snapshot bất biến”.

**Cách sửa:** nếu `status==='released'`, bỏ qua live check hoặc render panel “đã chốt — snapshot lịch sử”; chỉ chạy `check()` cho `planned`/`ready`.

**Test bắt buộc:** HTTP test chọn release đã chốt bằng developer và sau khi sửa issue; assert UI hiển thị trạng thái released, không hiển thị cảnh báo live.

---

## 7. Bảo mật, phân quyền và UI

### 7.1. Điểm mạnh bảo mật

| Kiểm soát | Bằng chứng |
|---|---|
| SQL injection | Query tham số hóa `$1...$n` gần như toàn bộ; không thấy nối input |
| XSS | `h()` dùng `htmlspecialchars(ENT_QUOTES, UTF-8)`; output được escape |
| CSRF | Mọi form POST có hidden `csrf`; controller kiểm tra token |
| Session fixation | `session_regenerate_id(true)` sau login (theo code login) |
| Cookie | `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS |
| Phân quyền backend | `requireProjectRoleInTransaction()` cho test/release; role check trong service, không chỉ ẩn UI |
| Cách ly project | Query đều có `project_id`; update/delete kiểm tra project |
| Password | `password_hash`/`password_verify` |
| Audit | Không ghi mật khẩu/token; chỉ ID/trạng thái/metadata |

### 7.2. Khoảng trống bảo mật — AUDIT-013

**Vị trí:** `src/App.php` dòng 9–17; `public/login.php` dòng 61; toàn bộ controller.

**Hiện tại:**

- Không rate limit đăng nhập → brute force.
- Không session timeout/idle timeout.
- Không security headers (`X-Frame-Options`, `Content-Security-Policy`, `X-Content-Type-Options`, `Strict-Transport-Security`).
- Trang login hiển thị **tài khoản demo và mật khẩu** `demo1234`.
- `Secure` cookie phụ thuộc `$_SERVER['HTTPS']`, có thể sai sau reverse proxy.
- Không có password reset, email verification, quản trị user toàn hệ thống.

**Kỳ vọng:** với ứng dụng lab, demo credentials có thể chấp nhận; với production, phải tắt và bổ sung hardening.

**Tác động:** tăng bề mặt tấn công; demo credentials là rủi ro nếu deploy công khai.

**Cách sửa:** rate limit theo IP/user, session timeout, security headers, tắt demo credentials khi `APP_ENV=production`, cấu hình `trusted_proxies` cho HTTPS detection.

**Test bắt buộc:** test login sai nhiều lần bị chặn; test header hiện diện; test session hết hạn.

### 7.3. Audit log chưa append-only — AUDIT-012

**Vị trí:** `database/migrations/001_initial_schema.sql` dòng 63; không có trigger `audit_log` trong migration 002/003.

**Hiện tại:** role ứng dụng có thể `UPDATE`/`DELETE` `audit_log` nếu có quyền SQL. Không có trigger chặn.

**Kỳ vọng:** audit log phải append-only; chỉ `INSERT`, cấm `UPDATE`/`DELETE` (trừ superuser/maintenance có kiểm soát).

**Tác động:** kẻ tấn công hoặc lỗi vận hành có thể xóa dấu vết.

**Cách sửa:** trigger `BEFORE UPDATE OR DELETE ON audit_log` raise exception; hoặc `REVOKE UPDATE, DELETE` khỏi role ứng dụng.

**Test bắt buộc:** `UPDATE`/`DELETE audit_log` phải ném exception; `INSERT` vẫn thành công.

### 7.4. UI/UX

**Điểm mạnh:**

- Giao diện tiếng Việt nhất quán, có phân cấp panel.
- Form có label, `maxlength`, `required`.
- Có thông báo lỗi và trạng thái release.
- Có trang audit, dependency, release notes Markdown.

**Điểm cần cải thiện:**

- **AUDIT-004**: release đã chốt vẫn hiển thị check live.
- **AUDIT-009**: dashboard blocked thiếu closed-but-failed.
- **AUDIT-010**: lỗi trùng tiêu đề hiển thị `DATABASE_OPERATION_FAILED` thay vì thông báo thân thiện.
- Không có `aria-*`, không có skip-link, không có focus management; chưa test keyboard/screen reader.
- Bảng điều kiện release dài, khó quét; nên nhóm theo “blocker / dependency / snapshot / role”.

### 7.5. Lỗi trùng tiêu đề — AUDIT-010

**Vị trí:** `src/IssueService.php` `create()`/`update()`; `src/Database.php` dòng 81–82, 102–103.

**Hiện tại:** unique constraint trên `(project_id,title)` (nếu có) ném SQLSTATE `23505`; `Database.php` bắt `Throwable` và ném `RuntimeException('DATABASE_OPERATION_FAILED')`.

**Tái hiện:** tạo 2 issue cùng tiêu đề trong cùng project → `RuntimeException: DATABASE_OPERATION_FAILED`.

**Kỳ vọng:** `ConflictException` với thông báo “Tiêu đề đã tồn tại trong dự án”.

**Tác động:** UX kém; người dùng không biết cách sửa; log có thể lộ chi tiết DB.

**Cách sửa:** catch `SQLSTATE 23505` trong `create/update`, map sang `ConflictException`; hoặc pre-check `SELECT 1 FROM issues WHERE project_id=$1 AND title=$2`.

**Test bắt buộc:** tạo trùng tiêu đề → `ConflictException`, message tiếng Việt; HTTP hiển thị lỗi thân thiện.

---

## 8. Danh sách phát hiện đầy đủ

### 8.1. Bảng tổng hợp

| ID | Tiêu đề | Mức | Trạng thái | Vị trí chính |
|---|---|---|---|---|
| AUDIT-001 | Stale test form được chấp nhận, không kiểm tra base version | **P1** | CONFIRMED | `IssueService.php:229-299`, `issues.php:285-299` |
| AUDIT-002 | Dependency bắc cầu không được kiểm tra/invalidate | **P1** | CONFIRMED | `ReleaseService.php:153-183,233-278`, `DependencyService.php:256-292` |
| AUDIT-003 | Migration 002 kiểm tra constraint không scope schema/table | **P1** | CONFIRMED | `002_integrity_columns.sql:7,22,29,...` |
| AUDIT-004 | Release đã chốt vẫn bị check live theo quyền/dữ liệu hiện tại | P2 | CONFIRMED | `releases.php:98-113` |
| AUDIT-005 | Đổi tên project làm thay đổi release notes lịch sử | P2 | CONFIRMED | `ReleaseNotes.php:13-15,27` |
| AUDIT-006 | Trigger cho phép move item ra khỏi release đã chốt | P2 | CONFIRMED | `003_release_guards.sql:9` |
| AUDIT-007 | Trigger cho phép `planned -> released` trực tiếp | P2 | CONFIRMED | `003_release_guards.sql:30-35` |
| AUDIT-008 | Item non-blocker `open` vẫn qua ready/release | P2 | CONFIRMED (policy) | `ReleaseService.php:201-232` |
| AUDIT-009 | `blockedForProject` bỏ sót closed blocker có test failed | P2 | CONFIRMED | `DependencyService.php:241-254` |
| AUDIT-010 | Trùng tiêu đề trả lỗi generic `DATABASE_OPERATION_FAILED` | P3 | CONFIRMED | `Database.php:81-82,102-103` |
| AUDIT-011 | Test thiếu coverage cho các lỗi P1/P2 | P2 | CONFIRMED | `tests/run.php`, `tests/integration.php` |
| AUDIT-012 | Audit log không append-only | P3 | LIKELY | `001_initial_schema.sql:63` |
| AUDIT-013 | Thiếu hardening bảo mật (rate limit, timeout, headers, demo creds) | P3 | CONFIRMED | `App.php:9-17`, `login.php:61` |
| AUDIT-014 | README overclaim “release notes đọc snapshot” | P3 | CONFIRMED | `README.md:172`, `ReleaseNotes.php` |
| AUDIT-015 | Seed không hỗ trợ demo end-to-end (release rỗng, không test) | P3 | CONFIRMED | `database/seed.sql` |

### 8.2. Chi tiết từng phát hiện

#### AUDIT-001 — Stale test form được chấp nhận

- **Mức:** P1 — **CONFIRMED**
- **Vị trí:** `src/IssueService.php` `recordTest()` dòng 229–299 (đặc biệt 255–260, 279–289); `public/issues.php` dòng 285–299 (form test thiếu `version`).
- **Hiện tại:** `recordTest()` không nhận version từ client; đọc version hiện tại và tăng lên 1. Form test không gửi version.
- **Kỳ vọng:** form gửi version đã thấy; service so sánh và từ chối nếu lệch, giống optimistic locking của `update()`.
- **Tái hiện:** issue v2 → developer sửa thành v3 → tester submit form cũ `passed` → được chấp nhận, issue thành v4 verified; `check` 0 problem; `markReady` SUCCESS.
- **Tác động:** test evidence không còn đại diện cho phiên bản tester đã kiểm tra; có thể chốt release dựa trên test “ảo”.
- **Cách sửa:** thêm hidden `version`; `recordTest(..., int $expectedVersion, ...)`; kiểm tra `issue.version === expectedVersion`, nếu không ném `ConflictException`; dùng `UPDATE ... WHERE version=$expected`.
- **Test bắt buộc:** load form v2, update v3, submit test cũ → `ConflictException`; không tạo `test_results`; issue không đổi.

#### AUDIT-002 — Dependency bắc cầu không được kiểm tra/invalidate

- **Mức:** P1 — **CONFIRMED**
- **Vị trí:** `src/ReleaseService.php` dòng 153–183, 233–278; `src/DependencyService.php` dòng 256–292.
- **Hiện tại:** `check()` chỉ duyệt dependency trực tiếp của item; `invalidateReadyReleases()` chỉ reset item phụ thuộc trực tiếp.
- **Kỳ vọng:** kiểm tra closure bắc cầu; invalidation lan truyền toàn bộ descendant.
- **Tái hiện:** A open ← B verified ← C verified; release chứa C → `check` 0 problem, `markReady` SUCCESS; A failed → release vẫn `ready`; release mới chứa C vẫn `markReady` SUCCESS.
- **Tác động:** release có thể chốt dù chuỗi phụ thuộc chưa hoàn thành; đây là lỗi nghiệp vụ nghiêm trọng.
- **Cách sửa:** recursive CTE/BFS để lấy closure; kiểm tra mọi node; invalidation theo closure.
- **Test bắt buộc:** như kịch bản trên; assert `check` có problem, `markReady` ném `ValidationException`, release `ready` bị reset `planned`.

#### AUDIT-003 — Migration 002 constraint không scope schema/table

- **Mức:** P1 — **CONFIRMED**
- **Vị trí:** `database/migrations/002_integrity_columns.sql` dòng 7, 22, 29, 63, 71, 79, 87, 118, 126, 134.
- **Hiện tại:** `IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='...')` không lọc `conrelid`/`connamespace`.
- **Kỳ vọng:** chỉ kiểm tra constraint thuộc đúng bảng/schema hiện tại.
- **Tái hiện:** schema `rt_audit_probe_*` có 103 constraint nhưng thiếu `uq_issues_id_project`, `fk_test_results_issue_project`, v.v.
- **Tác động:** test schema thiếu integrity constraint; deployment vào schema khác `public` có thể thiếu constraint mà không báo.
- **Cách sửa:** scope theo `conrelid`/`connamespace` hoặc dùng `to_regclass`; thêm test assert constraint tồn tại.
- **Test bắt buộc:** fresh schema → migrate → assert đủ constraint; chạy lại migration → idempotent.

#### AUDIT-004 — Release đã chốt vẫn bị check live

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `public/releases.php` dòng 98–113.
- **Hiện tại:** mọi release được chọn đều gọi `ReleaseService::check()` với role hiện tại.
- **Kỳ vọng:** release `released` hiển thị snapshot lịch sử; không đánh giá lại.
- **Tái hiện:** release_manager 0 problem; developer 1 role problem; sau khi sửa issue 1 stale-test problem; release vẫn `released`.
- **Tác động:** UI mâu thuẫn, gây hiểu nhầm về tính bất biến.
- **Cách sửa:** short-circuit khi `status==='released'`; render panel lịch sử.
- **Test bắt buộc:** HTTP test như trên; assert không có cảnh báo live.

#### AUDIT-005 — Đổi tên project làm đổi release notes

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `src/ReleaseNotes.php` dòng 11–15, 27.
- **Hiện tại:** join `projects` lấy `p.name` hiện tại.
- **Kỳ vọng:** snapshot tên project tại thời điểm release.
- **Tái hiện:** notes title `AuditRename ...` → `AuditRename Renamed`; notes identical = NO.
- **Tác động:** lịch sử release bị thay đổi; vi phạm snapshot bất biến ở metadata.
- **Cách sửa:** thêm `project_name` snapshot vào `releases`/`release_items`; notes đọc snapshot.
- **Test bắt buộc:** release → rename → notes byte-identical.

#### AUDIT-006 — Trigger cho phép move item ra khỏi release đã chốt

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `database/migrations/003_release_guards.sql` dòng 9.
- **Hiện tại:** `COALESCE(NEW.release_id, OLD.release_id)` chỉ kiểm tra release đích.
- **Kỳ vọng:** chặn nếu OLD hoặc NEW release đã `released`; chặn đổi khóa.
- **Tái hiện:** `move_result=ALLOWED`; số item release đã chốt 1 → 0.
- **Tác động:** snapshot có thể bị rút ruột bằng SQL.
- **Cách sửa:** kiểm tra cả OLD/NEW; raise nếu đổi `release_id`/`project_id`/`issue_id`.
- **Test bắt buộc:** move item → exception; count không đổi.

#### AUDIT-007 — Trigger cho phép `planned -> released`

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `database/migrations/003_release_guards.sql` dòng 30–35.
- **Hiện tại:** chỉ chặn `OLD.status='released'`.
- **Kỳ vọng:** chỉ `ready -> released`.
- **Tái hiện:** `direct_planned_to_released=ALLOWED`; `checked_at=NULL`, `released_at=NULL`.
- **Tác động:** bỏ qua check/snapshot/audit.
- **Cách sửa:** trigger transition matrix; ràng buộc timestamp.
- **Test bắt buộc:** planned→released phải exception; ready→released thành công.

#### AUDIT-008 — Item non-blocker `open` vẫn qua ready

- **Mức:** P2 — **CONFIRMED (policy gap)**
- **Vị trí:** `src/ReleaseService.php` dòng 201–232.
- **Hiện tại:** chỉ blocker được kiểm tra test.
- **Kỳ vọng:** tùy policy; nếu release yêu cầu mọi item verified/closed thì phải chặn.
- **Tái hiện:** `open_item_check_problems=0`, `markReady=SUCCESS`.
- **Tác động:** release có thể chốt với item chưa xong.
- **Cách sửa:** thêm condition `item_status` hoặc document rõ policy + cảnh báo UI.
- **Test bắt buộc:** theo policy đã chọn.

#### AUDIT-009 — `blockedForProject` bỏ sót closed blocker failed

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `src/DependencyService.php` dòng 241–254, đặc biệt 250.
- **Hiện tại:** loại `closed` khỏi danh sách bất kể test.
- **Kỳ vọng:** align với `check()` dòng 269–270.
- **Tái hiện:** `blocked_list_rows=0`, `release_check_problems=1`.
- **Tác động:** dashboard thiếu blocker, khó debug.
- **Cách sửa:** include closed nếu latest test không passed/không trust/stale.
- **Test bắt buộc:** closed blocker + failed → xuất hiện trong list và check.

#### AUDIT-010 — Trùng tiêu đề trả lỗi generic

- **Mức:** P3 — **CONFIRMED**
- **Vị trí:** `src/IssueService.php` `create/update`; `src/Database.php` dòng 81–82, 102–103.
- **Hiện tại:** SQLSTATE 23505 bị bọc thành `RuntimeException('DATABASE_OPERATION_FAILED')`.
- **Kỳ vọng:** `ConflictException` với message tiếng Việt.
- **Tái hiện:** tạo trùng title → `RuntimeException: DATABASE_OPERATION_FAILED`.
- **Tác động:** UX kém, khó hiểu.
- **Cách sửa:** map 23505 → `ConflictException`; hoặc pre-check.
- **Test bắt buộc:** duplicate title → `ConflictException`, HTTP friendly.

#### AUDIT-011 — Test thiếu coverage

- **Mức:** P2 — **CONFIRMED**
- **Vị trí:** `tests/run.php`, `tests/integration.php`.
- **Hiện tại:** 43 + 16 test pass, nhưng không có test cho: stale test form/version, transitive dependency, project rename sau release, trigger move item, direct planned→released, open non-blocker, migration constraint scoping, released release live-check.
- **Kỳ vọng:** mỗi bất biến P1/P2 có test hồi quy.
- **Tác động:** lỗi P1 tồn tại dù test xanh → false confidence.
- **Cách sửa:** thêm test tương ứng từng finding.
- **Test bắt buộc:** chính các test ở trên.

#### AUDIT-012 — Audit log không append-only

- **Mức:** P3 — **LIKELY**
- **Vị trí:** `database/migrations/001_initial_schema.sql` dòng 63; không có trigger trong 002/003.
- **Hiện tại:** không có ràng buộc DB chặn UPDATE/DELETE audit_log.
- **Kỳ vọng:** append-only.
- **Tái hiện:** chưa chạy thử UPDATE/DELETE trên audit_log (tránh sửa dữ liệu thật); suy ra từ schema/trigger.
- **Tác động:** audit có thể bị xóa/sửa bởi role có quyền.
- **Cách sửa:** trigger raise hoặc REVOKE.
- **Test bắt buộc:** UPDATE/DELETE phải exception.

#### AUDIT-013 — Thiếu hardening bảo mật

- **Mức:** P3 — **CONFIRMED**
- **Vị trí:** `src/App.php` dòng 9–17; `public/login.php` dòng 61.
- **Hiện tại:** không rate limit, không session timeout, không security headers, demo credentials trên login, `Secure` phụ thuộc `$_SERVER['HTTPS']`.
- **Kỳ vọng:** production hardening.
- **Tái hiện:** đọc code; không cần chạy.
- **Tác động:** brute force, clickjacking, session sống quá lâu, lộ demo creds.
- **Cách sửa:** rate limit, timeout, headers, tắt demo creds, trusted proxy.
- **Test bắt buộc:** login fail nhiều lần bị chặn; header hiện diện; session hết hạn.

#### AUDIT-014 — README overclaim snapshot notes

- **Mức:** P3 — **CONFIRMED**
- **Vị trí:** `README.md` dòng 172; `src/ReleaseNotes.php`.
- **Hiện tại:** README nói notes đọc snapshot, không đọc issue hiện tại. Đúng với field issue, nhưng **tên project** vẫn đọc live.
- **Kỳ vọng:** tài liệu phản ánh đúng giới hạn.
- **Tái hiện:** AUDIT-005.
- **Tác động:** người đọc tin snapshot bất biến hoàn toàn.
- **Cách sửa:** snapshot project name hoặc sửa README.
- **Test bắt buộc:** rename test.

#### AUDIT-015 — Seed không hỗ trợ demo end-to-end

- **Mức:** P3 — **CONFIRMED**
- **Vị trí:** `database/seed.sql`.
- **Hiện tại:** 3 release rỗng; 0 release_item; 0 test_result.
- **Kỳ vọng:** seed có thể có ít nhất một release demo với item/test, hoặc README nói rõ phải thao tác tay.
- **Tái hiện:** seed counts.
- **Tác động:** người chấm/demo phải setup thủ công; khó thấy giá trị snapshot ngay.
- **Cách sửa:** thêm release demo + test results + release items (vẫn giữ invariant).
- **Test bắt buộc:** seed test assert demo release có item và notes.

---

## 9. Kiểm thử, truy vết yêu cầu, lộ trình và kết luận

### 9.1. Kết quả chạy thực tế

| Lệnh | Kết quả quan sát |
|---|---|
| `bash lab.sh check` | `MOI_TRUONG=SAN_SANG | Lab 5` |
| `php tests/run.php` | `TESTS=43 FAILED=0` |
| `php tests/integration.php` | `INTEGRATION=16 FAILED=0` |
| `bash tests/backup-isolated.sh` | `RESULT PASS=59 FAIL=0 SKIP=1` (thiếu `age`/`age-keygen`) |
| `php tools/migrate.php --status` | 3 migration `APPLIED`; `public` không đổi |
| `php tools/legacy-report.php --json` | 5 legacy test, 0 trusted, 5 untrusted, 3 `verified_without_valid_test`, 4 `blocker_without_valid_test` |
| Seed counts (schema tạm) | `{"users":"5","projects":"2","issues":"40","dependencies":"15","releases":"3","release_items":"0","test_results":"0"}` |
| Dọn schema tạm | `residual_rt_schemas=0` |

**Ghi chú:** test được chạy trong schema `rt_test_*`; reproduction chạy trong `rt_audit_probe_*`; không chạy migration/seed/UPDATE/DELETE trên `public`.

### 9.2. Kiểm chứng 5 test bắt buộc

| # | Test bắt buộc | Kết quả | Bằng chứng | Khoảng trống |
|---|---|---|---|---|
| 1 | Từ chối chu trình dependency | **PASS** | TEST 1, TEST 1b, graph unit; `findCycle` đúng; DB không lưu cạnh vòng | Chỉ test chu trình trực tiếp; chưa test đồ thị lớn/sâu |
| 2 | Blocker chưa retest không được ready/release | **PASS (direct)** | TEST 2: thiếu test → chặn; failed → chặn; passed → ready; release planned → conflict | **FAIL bắc cầu** (AUDIT-002): blocker gián tiếp không bị phát hiện |
| 3 | Concurrent edit | **PASS (issue update)** | TEST 3a/3b/3c: optimistic locking, hai process, dữ liệu client thắng | **FAIL test form** (AUDIT-001): recordTest không có base version |
| 4 | Project isolation | **PASS (app layer)** | TEST 4 + integration: update/query chéo project bị chặn; dữ liệu project B không đổi | **DB constraint gap** (AUDIT-003): composite FK có thể thiếu trong schema test |
| 5 | Immutable release snapshot | **PASS (normal path)** | TEST 5: release → sửa issue → snapshot/notes không đổi; trigger chặn update/delete thường | **FAIL metadata/trigger** (AUDIT-005/006/007): rename project, move item, direct transition |

### 9.3. Truy vết 2 yêu cầu kỹ thuật core

#### Yêu cầu 1 — Tự cài đặt thuật toán đồ thị và điều kiện phát hành

**Kết luận: PASS có caveat.**

- `findPath`, `findCycle`, `adjacency`, `projectCycle` được cài tay, không dùng thư viện.
- TEST 1/1b và graph unit pass.
- Caveat: điều kiện phát hành chỉ kiểm tra dependency **trực tiếp**, không closure → chưa hoàn chỉnh về mặt nghiệp vụ (AUDIT-002).

#### Yêu cầu 2 — Phát hiện xung đột ghi đồng thời + snapshot bất biến

**Kết luận: PARTIAL.**

- **Đạt:** optimistic locking cho `issues.update()`; project lock; release transaction; snapshot field; trigger cho đường đi thường.
- **Chưa đạt:**
  - `recordTest()` không kiểm tra base version → stale form (AUDIT-001).
  - Snapshot tên project không bất biến (AUDIT-005).
  - Trigger cho phép move item và direct transition (AUDIT-006/007).
  - Release đã chốt vẫn bị check live (AUDIT-004).

### 9.4. Ma trận truy vết yêu cầu

| ID | Yêu cầu | Trạng thái | Bằng chứng | Finding |
|---|---|---|---|---|
| R1 | Tự cài thuật toán đồ thị | PASS | `DependencyService::findPath/findCycle`; TEST 1 | — |
| R2 | Phát hiện chu trình, không lưu cạnh vòng | PASS | TEST 1; `projectCycle` | — |
| R3 | Điều kiện release đầy đủ | PARTIAL | `check()` 7 conditions | AUDIT-002, 008 |
| R4 | Blocker phải retest | PASS direct | TEST 2 | AUDIT-002 (transitive) |
| R5 | Phát hiện xung đột ghi đồng thời | PARTIAL | TEST 3a/3b/3c | AUDIT-001 |
| R6 | Snapshot release bất biến | PARTIAL | TEST 5; trigger | AUDIT-005/006/007 |
| R7 | Cách ly project | PASS app layer | TEST 4; integration | AUDIT-003 (DB) |
| R8 | Audit trail | PARTIAL | audit trong transaction | AUDIT-012 |
| R9 | Seed 2/40/15/3 | PASS | seed counts | AUDIT-015 (demo) |
| R10 | Test 43 + 16 pass | PASS | commands | AUDIT-011 (coverage) |

### 9.5. Kiểm chứng tuyên bố README

| Tuyên bố | Kết quả | Ghi chú |
|---|---|---|
| 43 test service/DB | **ĐÚNG** | `TESTS=43 FAILED=0` |
| 16 test HTTP | **ĐÚNG** | `INTEGRATION=16 FAILED=0` |
| Seed 2 project, 40 issue, 15 dependency, 3 release, 5 user | **ĐÚNG** | seed counts |
| Seed không có test_results, không có issue verified | **ĐÚNG** | counts + seed |
| Legacy 5 test, 3 verified without valid test, 4 blocker without valid test | **ĐÚNG** | legacy report JSON |
| Release notes đọc snapshot, không đọc issue hiện tại | **PARTIAL** | issue fields đúng; project name live (AUDIT-005/014) |
| Trigger chặn sửa release_items sau chốt | **PARTIAL** | update/delete thường đúng; move item bypass (AUDIT-006) |
| CI chưa cấu hình | **ĐÚNG** | không có workflow |
| Chưa rate limit/reset/session timeout | **ĐÚNG** | AUDIT-013 |

### 9.6. Lộ trình khắc phục

#### Ưu tiên 1 — P1 (phải sửa trước khi coi là đạt)

1. **AUDIT-001**: thêm base version vào form test + `recordTest()`; test stale form.
2. **AUDIT-002**: recursive closure cho check + invalidation; test chuỗi A←B←C.
3. **AUDIT-003**: scope `pg_constraint` theo schema/table; test fresh schema + idempotent.

#### Ưu tiên 2 — P2 (nên sửa trong sprint kế)

4. **AUDIT-004**: release đã chốt hiển thị snapshot, không check live.
5. **AUDIT-005**: snapshot `project_name` tại release.
6. **AUDIT-006/007**: hardening trigger (move item, transition matrix).
7. **AUDIT-008**: quyết định policy open non-blocker + document/UI.
8. **AUDIT-009**: align `blockedForProject` với `check()`.
9. **AUDIT-011**: bổ sung test hồi quy cho toàn bộ P1/P2.

#### Ưu tiên 3 — P3 (cải thiện)

10. **AUDIT-010**: map duplicate title → `ConflictException`.
11. **AUDIT-012**: audit append-only trigger/REVOKE.
12. **AUDIT-013**: rate limit, session timeout, security headers, tắt demo creds.
13. **AUDIT-014**: sửa README hoặc snapshot project name.
14. **AUDIT-015**: seed demo release có item/test.

#### Dài hạn

- Thêm CI (GitHub Actions) chạy `lab.sh check`, `tests/run.php`, `tests/integration.php`.
- Thêm static analysis (PHPStan/Psalm) và coding standard.
- Thêm browser E2E (Playwright) + accessibility audit.
- Thêm load test cho project lock và release transaction.
- Cân nhắc tách `ReleaseService::check()` thành các rule object nhỏ, dễ test.

### 9.7. Bằng chứng còn thiếu

| Bằng chứng | Lý do | Ảnh hưởng đến kết luận |
|---|---|---|
| Git history | Thư mục không phải git repo | Không xác minh được authorship, thay đổi, baseline |
| CI run | Chưa cấu hình | Không có bằng chứng pipeline thật |
| Browser/accessibility | Chỉ HTTP integration, không trình duyệt thật | Chưa kết luận được WCAG/responsive |
| Load/performance | Chưa chạy | Chưa biết behavior dưới tải |
| Production DB đầy đủ | Chỉ đọc legacy report | Chưa audit dữ liệu production ngoài legacy |
| Docker | Daemon không khởi động | Dùng WSL thay thế; môi trường khác có thể khác |
| Static analysis | Chưa cấu hình | Có thể còn lỗi type/edge case |
| Migration trên database mới hoàn toàn | Role không có `CREATEDB` | Chỉ test trên schema mới, không phải DB mới |
| Backup restore thật | `backup-isolated.sh` SKIP 1 do thiếu `age` | Chưa xác minh mã hóa backup |

### 9.8. Kết luận cuối cùng

**Verdict: CONDITIONAL PASS cho mục tiêu lab; NOT PRODUCTION-READY.**

Lý do:

- **Điểm tích cực:** kiến trúc rõ, code dễ đọc, **50 test service/DB + 20 test HTTP pass**, seed đúng, legacy report đúng, các kiểm soát bảo mật cơ bản (CSRF, escaping, parameterized query, role check) tốt.
- **Điểm trừ nghiêm trọng:** ba lỗi P1 ảnh hưởng trực tiếp đến niềm tin vào điều kiện phát hành và snapshot:
  1. Test form cũ vẫn được chấp nhận (AUDIT-001).
  2. Dependency bắc cầu không được kiểm tra/invalidate (AUDIT-002).
  3. Migration bỏ qua constraint trong schema mới (AUDIT-003).
- **Hệ quả:** không thể tuyên bố “đáp ứng đầy đủ yêu cầu đồ thị + đồng thời + snapshot bất biến” dựa trên test hiện có, vì test xanh nhưng bỏ sót đúng các kịch bản then chốt.

**Khuyến nghị:** sửa 3 P1, bổ sung test hồi quy tương ứng, rồi chạy lại toàn bộ 50 + 20 + backup test. Sau đó có thể xem xét **PASS** cho lab. Trước khi production, cần xử lý thêm P2/P3 và bổ sung CI/static analysis/browser test.

---

*Báo cáo gốc được tạo ngày 2026-10-28. Phase 2 đã sửa mã nguồn/migration/test theo phạm vi AUDIT-004–009 và AUDIT-011; chi tiết ở Mục 11.*

---

## 10. Cập nhật khắc phục P1 — 2026-10-28

### Trạng thái

| ID | Trạng thái | Khắc phục |
|---|---|---|
| AUDIT-001 | **RESOLVED** | Form kiểm thử gửi `version`; `IssueService::recordTest()` bắt buộc đối chiếu version đã hiển thị trước khi ghi evidence/đổi trạng thái và trả `ConflictException` khi form cũ. |
| AUDIT-002 | **RESOLVED** | `ReleaseService::check()` duyệt closure dependency bằng recursive CTE; `invalidateReadyReleases()` lan truyền tới mọi dependent bắc cầu và hạ release `ready` không còn hợp lệ về `planned`. |
| AUDIT-003 | **RESOLVED** | Các điều kiện constraint của migration 002 nay scope đồng thời theo tên constraint, bảng và `current_schema()`. |

### File đã sửa

- `src/IssueService.php`, `public/issues.php`
- `src/ReleaseService.php`, `src/DependencyService.php`
- `database/migrations/002_integrity_columns.sql`
- `tests/run.php`, `tests/integration.php`

### Regression và xác minh đã chạy

| Lệnh/nhóm | Kết quả thực tế |
|---|---|
| `bash lab.sh check` | `MOI_TRUONG=SAN_SANG` |
| `php tests/run.php` | `TESTS=46 FAILED=0`; gồm regression AUDIT-001 (service stale version), AUDIT-002 (A←B←C, check/invalidation/release mới), AUDIT-003 (constraint đúng schema, re-run migration) |
| `php tests/integration.php` | `INTEGRATION=17 FAILED=0`; gồm HTTP regression form test cũ gửi version 1 sau khi issue đã lên version 2 và bị từ chối |
| `bash tests/backup-isolated.sh` | `RESULT PASS=59 FAIL=0 SKIP=1`; phần encryption vẫn SKIP vì thiếu `age`/`age-keygen` |

Migration chỉ được chạy trong các schema test mới `rt_test_*` và re-run trong schema đó để kiểm tra idempotent. Không chạy migration, seed, UPDATE hoặc DELETE trên schema `public`. `tools/migrate.php --status` trước khi sửa xác nhận 001–003 đã applied trên `public`.

### Vấn đề còn tồn tại

Các finding P2/P3 (AUDIT-004 đến AUDIT-015) chưa được xử lý trong đợt này, theo đúng phạm vi yêu cầu. Legacy data trên `public` vẫn có test evidence không đáng tin cậy như báo cáo gốc; không có thao tác sửa dữ liệu nào được thực hiện.

---

## 11. Phase 2 — Khắc phục AUDIT-004..009 và hoàn thiện AUDIT-011

### 11.1. Trạng thái trước/sau

| ID | Trước Phase 2 | Sau Phase 2 | Ghi chú |
|---|---|---|---|
| AUDIT-004 | Chưa xử lý | **RESOLVED** | Release `released` không còn gọi live check; hiển thị snapshot lịch sử; POST check bị chặn. |
| AUDIT-005 | Chưa xử lý | **RESOLVED** | Markdown + HTML dùng `project_name_snapshot`; release cũ thiếu snapshot hiển thị cảnh báo, không dùng tên hiện tại. |
| AUDIT-006 | Chưa xử lý | **RESOLVED** | Trigger kiểm tra cả release nguồn/đích; chặn UPDATE/DELETE/INSERT vào release đã chốt. |
| AUDIT-007 | Chưa xử lý | **RESOLVED** | Trigger chỉ cho `ready -> released` với timestamp; migration 005 chặn INSERT `ready/released`. |
| AUDIT-008 | Chưa xử lý | **PARTIAL** | UI đã cảnh báo item non-blocker còn open; **chưa chốt policy gate**, cần người dùng xác nhận. |
| AUDIT-009 | Chưa xử lý | **RESOLVED** | `blockedForProject()` đồng bộ với `ReleaseService::check()` cho failed/stale/untrusted/no-test; có test stale + untrusted. |
| AUDIT-011 | Chưa đủ coverage | **PARTIAL** | Đã có regression cho 001–007 và 009; riêng gate của 008 chờ quyết định policy. |

### 11.2. Nguyên nhân gốc và cách sửa

#### AUDIT-004 — Release đã chốt vẫn bị đánh giá live

- **Nguyên nhân:** `public/releases.php` gọi `ReleaseService::check()` cho mọi release được chọn, kể cả `released`.
- **Sửa:** chỉ gọi `check()` khi `status !== 'released'`; nhánh `released` render panel “snapshot lịch sử đã chốt”. POST `action=check` trên release đã chốt bị từ chối bằng `ConflictException`.
- **File:** `public/releases.php`.

#### AUDIT-005 — Đổi tên project làm đổi release notes

- **Nguyên nhân:** `ReleaseNotes::markdown()` và `public/release-notes.php` join `projects.name` hiện tại; migration 004 mới chỉ thêm cột snapshot nhưng HTML chưa dùng.
- **Sửa:** thêm `ReleaseNotes::displayProjectName()`; markdown và HTML cùng dùng helper. Released release dùng `project_name_snapshot`; release cũ thiếu snapshot hiển thị `[không có snapshot tên dự án lịch sử]`, không backfill.
- **File:** `src/ReleaseNotes.php`, `public/release-notes.php`; cột `releases.project_name_snapshot` do migration 004.

#### AUDIT-006 — Di chuyển item ra khỏi release đã chốt

- **Nguyên nhân:** trigger cũ dùng `COALESCE(NEW.release_id, OLD.release_id)`, chỉ kiểm tra release đích.
- **Sửa:** trigger `rt_guard_release_items()` đọc riêng `source_status` (OLD) và `target_status` (NEW); từ chối nếu một trong hai là `released`; giữ quy tắc INSERT/DELETE chỉ trên `planned`, UPDATE chỉ trên `planned/ready`.
- **File:** `database/migrations/004_release_history_guards.sql` (đã có trong baseline Phase 2), được kiểm chứng lại bằng SQL trực tiếp.

#### AUDIT-007 — planned -> released trực tiếp

- **Nguyên nhân:** trigger cũ chỉ chặn khi `OLD.status='released'`, không kiểm tra transition vào `released`.
- **Sửa:** trigger yêu cầu `OLD.status='ready'` và `checked_at`/`released_at` khác NULL khi `NEW.status='released'`; migration 005 thêm trigger `BEFORE INSERT` chỉ cho release mới bắt đầu ở `planned`.
- **File:** `database/migrations/004_release_history_guards.sql`, `database/migrations/005_release_insert_guard.sql` (mới).

#### AUDIT-008 — Item non-blocker còn open

- **Nguyên nhân:** `ReleaseService::check()` chỉ kiểm tra priority `blocker` và dependency; không có điều kiện trạng thái cho item non-blocker. README/lab không quy định rõ policy.
- **Sửa một phần:** `public/releases.php` tính `$incompleteItems` và hiển thị cảnh báo khi release còn item chưa `verified/closed`; **không thêm gate** để tránh tự ý đổi chính sách.
- **Trạng thái:** **PARTIAL/BLOCKED** — cần quyết định: (A) cho phép item non-blocker open và giữ cảnh báo, hoặc (B) thêm condition chặn tất cả item chưa `verified/closed`.

#### AUDIT-009 — blockedForProject không khớp release check

- **Nguyên nhân:** query cũ loại `closed` khỏi danh sách bất kể test evidence; thiếu xử lý NULL cho audit details.
- **Sửa:** `blockedForProject()` kiểm tra latest test + provenance giống `TestEvidence::isTrusted()`; dùng `COALESCE` cho `result`, `tester_role`, `issue_version`; nhánh `closed` chỉ bỏ qua khi latest test `passed`. Test bổ sung cho stale pass và untrusted pass.
- **File:** `src/DependencyService.php`, `tests/run.php`.

### 11.3. File đã sửa trong Phase 2

| File | Thay đổi |
|---|---|
| `src/ReleaseNotes.php` | Thêm `displayProjectName()`; markdown dùng snapshot tên project, fallback legacy rõ ràng. |
| `public/release-notes.php` | HTML dùng `displayProjectName()` thay vì `projects.name` hiện tại. |
| `public/releases.php` | Cảnh báo item chưa `verified/closed`; giữ nguyên live check cho release chưa chốt. |
| `src/DependencyService.php` | `blockedForProject()` NULL-safe và đồng bộ evidence với release check. |
| `database/migrations/005_release_insert_guard.sql` | **Mới:** chặn INSERT release ở `ready/released`. |
| `database/schema.sql` | Include migration 005. |
| `tests/run.php` | Thêm INSERT guard, idempotency 005, AUDIT-009b stale/untrusted. |
| `tests/integration.php` | Thêm developer released view, AUDIT-005 HTML/Markdown/legacy, AUDIT-008 warning. |
| `README.md` | Cập nhật 50/20 test, migration 004/005, snapshot tên project, giới hạn policy AUDIT-008. |
| `AUDIT_REPORT.md` | Cập nhật trạng thái và báo cáo Phase 2. |

**Lưu ý:** `public/releases.php` (skip live check), `ReleaseService::release()` (ghi `project_name_snapshot`), migration 004 và các test AUDIT-005/006/007/009 đã có trong baseline trước Phase 2. Phase 2 kiểm chứng lại, bổ sung phần HTML/INSERT/stale/untrusted/warning và migration 005.

### 11.4. Migration mới

**`database/migrations/005_release_insert_guard.sql`**

- Tạo function `rt_guard_release_insert()`.
- Trigger `trg_release_insert_guard BEFORE INSERT ON releases`.
- Chỉ cho `NEW.status='planned'`; `ready`/`released` bị từ chối với `RELEASE_INVALID_INITIAL_STATUS`.
- Idempotent: `CREATE OR REPLACE FUNCTION` + `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER`.

Migration 004 và 005 **chưa áp dụng vào `public`**. `php tools/migrate.php --status` xác nhận 004–005 `PENDING`; không chạy `migrate` trên `public` trong đợt này.

### 11.5. Regression test mới

| Test | Loại | Kịch bản |
|---|---|---|
| `HTTP AUDIT-004` (mở rộng) | HTTP | Developer trong project đọc release đã chốt; thấy snapshot, không thấy live check; user ngoài project nhận 403. |
| `HTTP AUDIT-005` | HTTP | Release → đổi tên project → HTML và Markdown vẫn giữ tên cũ; release legacy thiếu snapshot hiển thị cảnh báo; user ngoài project 403. |
| `HTTP AUDIT-008` | HTTP | Release có item non-blocker `open` hiển thị cảnh báo số lượng và ID item; không tuyên bố release đã hoàn tất. |
| `AUDIT-006/007` (mở rộng) | DB trigger | INSERT vào release đã chốt bị từ chối; move item vào release đã chốt bị từ chối; INSERT release `ready/released` bị từ chối; INSERT `planned` được phép; re-run migration 004/005 idempotent. |
| `AUDIT-009b` | Service/DB | Dependency verified với passed stale bị list/check; passed untrusted (audit bị xóa) bị list/check; passed current hợp lệ không bị list. |
| `AUDIT-001/002/003/005/009` (hiện có) | Service/DB | Giữ nguyên và chạy lại toàn bộ. |

### 11.6. Lệnh đã chạy và kết quả thực tế

| Lệnh | Kết quả |
|---|---|
| `bash lab.sh check` | `MOI_TRUONG=SAN_SANG \| Lab 5` |
| `php tests/run.php` | `TESTS=50 FAILED=0` |
| `php tests/integration.php` | `INTEGRATION=20 FAILED=0` |
| `bash tests/backup-isolated.sh` | `RESULT PASS=59 FAIL=0 SKIP=1` (thiếu `age`/`age-keygen`) |
| `php tools/migrate.php --status` | 001–003 `APPLIED`; 004–005 `PENDING`; cảnh báo checksum 002 đã đổi so với lần apply trên `public` |
| Kiểm tra schema tạm | `residual_rt_schemas=0` |

Trước khi sửa, hai test mới đã **FAIL đúng dự kiến**:
- `HTTP AUDIT-005`: HTML vẫn hiển thị tên project đã đổi.
- `HTTP AUDIT-008`: chưa có cảnh báo item open.

Sau khi sửa, cả hai PASS. Đây là bằng chứng regression test thực sự tái hiện lỗi cũ, không chỉ kiểm tra HTTP 200.

### 11.7. Finding còn PARTIAL/BLOCKED

| ID | Trạng thái | Lý do | Cần làm |
|---|---|---|---|
| AUDIT-008 | **PARTIAL/BLOCKED** | Chưa có căn cứ nghiệp vụ để quyết định item non-blocker `open` có chặn release hay không. | Người dùng chốt policy A/B; nếu B, thêm condition `item_status` + test; nếu A, giữ cảnh báo và ghi tài liệu. |
| AUDIT-011 | **PARTIAL** | Đã cover 001–007, 009; gate của 008 phụ thuộc policy. | Bổ sung test gate sau khi policy được chốt. |

Các finding P3 còn lại (AUDIT-010, 012, 013, 014, 015) **chưa xử lý** trong Phase 2, đúng phạm vi yêu cầu.

### 11.8. Phạm vi database đã dùng

- **Không** chạy migration, seed, `UPDATE`, `DELETE`, `TRUNCATE` trên `public`.
- Toàn bộ test ghi dữ liệu chạy trong schema `rt_test_*` do `tests/bootstrap.php` tạo và drop.
- Migration 004/005 được kiểm tra trong schema test mới; re-run để xác nhận idempotent.
- `php tools/migrate.php --status` chỉ đọc; không tạo `schema_migrations`.
- Kiểm tra cuối: `residual_rt_schemas=0`.

### 11.9. Rủi ro còn tồn tại

1. **AUDIT-008 policy:** UI cảnh báo nhưng gate vẫn cho item non-blocker `open` đi qua ready/release. Đây là quyết định sản phẩm, không phải lỗi kỹ thuật đã chốt.
2. **Migration 002 checksum:** `public` đã apply 002 trước khi P1 sửa scoping; `migrate --status` cảnh báo checksum changed. Không tự ý `--reapply` trên `public`; cần kế hoạch migration có kiểm soát.
3. **Migration 004/005 pending:** trigger bảo vệ mới chỉ có trong schema test; `public` chưa được bảo vệ cho đến khi apply 004/005.
4. **DB trigger không thể kiểm tra toàn bộ business rule:** trigger chặn shape/transition và snapshot key, nhưng direct SQL có quyền cao vẫn có thể tạo dữ liệu giả nếu cố tình. Service vẫn là lớp gate chính.
5. **Không có Git repo/CI:** không có diff lịch sử, không có pipeline xác nhận trên môi trường sạch.
6. **Backup encryption SKIP:** thiếu `age`/`age-keygen`; chưa xác minh nhánh mã hóa.

### 11.10. Diff summary (không có Git)

Thư mục không phải Git repository nên không thể cung cấp `git diff`. Tóm tắt theo file:

```text
src/ReleaseNotes.php          + displayProjectName(); markdown dùng helper
public/release-notes.php      + $displayProjectName; HTML dùng snapshot
public/releases.php           + $incompleteItems; cảnh báo item chưa verified/closed
src/DependencyService.php     ~ COALESCE evidence; stale/untrusted/NULL-safe
database/migrations/005...    + trigger chặn INSERT release ready/released
database/schema.sql           + include 005
tests/run.php                 + INSERT guard, idempotency 005, AUDIT-009b
tests/integration.php         + developer released view, AUDIT-005 HTML, AUDIT-008
README.md                     ~ 50/20 test, migration 004/005, snapshot, policy note
AUDIT_REPORT.md               ~ trạng thái Phase 2 + Mục 11
```

### 11.11. Kết luận Phase 2

- **Đã đạt:** AUDIT-004, 005, 006, 007, 009 RESOLVED với regression test và kiểm chứng SQL trực tiếp trên schema test.
- **Chưa đạt:** AUDIT-008 cần quyết định policy; AUDIT-011 chỉ PARTIAL vì gate của 008 chưa có policy.
- **Không tuyên bố production-ready:** còn migration 004/005 chưa apply trên `public`, checksum 002 lệch, các P3 chưa xử lý và chưa có CI/browser/static analysis.
