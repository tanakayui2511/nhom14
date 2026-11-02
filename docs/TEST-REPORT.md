# BÁO CÁO KIỂM THỬ RELEASETRACKER

**Ngày lập:** 2026-11-01  
**Phạm vi:** kiểm thử runtime đã xác nhận và static review repository  
**Môi trường runtime:** PHP 8.5.4, PostgreSQL 18.6  
**Trạng thái tổng thể:** baseline 71/71 PASS; regression sau Dependency Graph là NOT RUN trong shell hiện tại; production security vẫn BLOCKED bởi DBA

### Cập nhật runtime

Theo lần chạy runtime mới nhất được cung cấp: `TESTS=51 FAILED=0`, service exit
code `0`; `INTEGRATION=20 FAILED=0`, integration exit code `0`. Tổng cộng 71/71
test thành công. Đây là baseline runtime trước phần mở rộng coverage của
Dependency Graph trong lượt hiện tại. TEST 2c đã PASS sau khi fixture được sửa để release item đạt
điều kiện trước, qua đó kiểm tra đúng dependency blocker closed thiếu evidence.

`DATABASE_OPERATION_FAILED: TEST_FAIL_RELEASE` là lỗi được tạo có chủ đích trong
test rollback; test rollback tương ứng vẫn PASS.

## I. Tổng quan kiểm thử

### Mục tiêu

Đánh giá các module đang tồn tại của ReleaseTracker, tập trung vào năm yêu cầu
kỹ thuật bắt buộc:

1. Dependency cycle bị từ chối.
2. Blocker chưa retest ngăn release.
3. Optimistic locking ngăn lost update.
4. Thành viên project A không sửa issue project B.
5. Snapshot cũ không thay đổi khi issue gốc tiếp tục được chỉnh sửa.

Ngoài ra, rà soát authentication, authorization, CSRF, XSS, validation, audit,
release gate, migration guard và giao diện conflict.

### Source và công cụ

- PHP 8.1+ và PostgreSQL là runtime mục tiêu.
- `tests/run.php` có 51 lời gọi `testCase`.
- `tests/integration.php` có 20 lời gọi `testCase`.
- `tests/bootstrap.php` thiết kế schema ngẫu nhiên `rt_test_*`, áp dụng migration
  và seed trong schema test rồi đăng ký cleanup.
- Có 6 migration hiện tại; không có migration 007.
- `docs/UI-DESIGN.md` chỉ là thiết kế, chưa phải UI đã triển khai.

### Kết quả kiểm tra môi trường

- Runtime test đã chạy trên PHP 8.5.4 và PostgreSQL 18.6.
- `tests/run.php`: 51/51, `FAILED=0`, exit code 0.
- `tests/integration.php`: 20/20, `FAILED=0`, exit code 0.
- Các kết quả runtime trên là thông tin đã được cung cấp; phiên shell hiện tại
  của agent vẫn không có `php`/`psql` trong `PATH`, nên không tự chạy lại được.
- Không có kết quả nào chứng minh database privileges production đã an toàn.

## II. Danh sách chức năng đã kiểm tra

| STT | Module | Chức năng | Trạng thái | Bằng chứng |
|---:|---|---|---|---|
| 1 | Authentication | Login, logout, session, session cookie | STATIC REVIEW | `src/App.php`, `public/login.php`, `public/logout.php` |
| 2 | Authorization | Project membership và role | STATIC REVIEW | `src/App.php`, `src/ProjectService.php` |
| 3 | Project | Tạo/sửa project và quản lý member | STATIC REVIEW | `src/ProjectService.php`, `public/projects.php` |
| 4 | Issue | Tạo/sửa, status, priority, assignee | STATIC REVIEW | `src/IssueService.php`, `public/issues.php` |
| 5 | Comment | Thêm comment và escaping khi render | STATIC REVIEW | `src/IssueService.php`, `src/App.php` |
| 6 | Test result | Ghi kết quả theo issue version/provenance | STATIC REVIEW | `src/IssueService.php`, `src/TestEvidence.php` |
| 7 | Dependency | Add/remove, project scope, duplicate, self-dependency | STATIC REVIEW | `src/DependencyService.php` |
| 8 | Dependency cycle | DFS path/cycle và project cycle | STATIC REVIEW | `DependencyService::findCycle()` |
| 9 | Blocker | Blocked list và evidence kiểm thử | STATIC REVIEW | `blockedForProject()`, `ReleaseService::check()` |
| 10 | Release | Create, add/remove item, ready, released | STATIC REVIEW | `src/ReleaseService.php`, `public/releases.php` |
| 11 | Release Gate | Item status, blocker, dependency, cycle, role | STATIC REVIEW | `ReleaseService::check()` |
| 12 | Snapshot | Snapshot item và project name | STATIC REVIEW | `ReleaseService::release()` |
| 13 | Release Notes | Markdown/TXT từ snapshot | STATIC REVIEW | `src/ReleaseNotes.php`, `public/release-notes.php` |
| 14 | Audit | Ghi audit và guard append-only | STATIC REVIEW | `src/App.php`, migration 006 |
| 15 | Conflict UI | Link tải lại dữ liệu sau `ConflictException` | STATIC REVIEW | `public/issues.php` |

Các dòng trên là inventory chức năng; kết quả PASS runtime được tổng hợp ở mục
"Cập nhật runtime" và ma trận xác nhận bên dưới.

## III. Kết quả từng test case

| Test ID | Test case | Expected result | Actual result | Status |
|---|---|---|---|---|
| TC-01 | Dependency cycle A-B-C-A | Từ chối và rollback cạnh gây vòng | PASS trong baseline service/database suite | PASS (baseline) |
| TC-02 | Blocker chưa retest | Không cho ready/released | PASS trong baseline, gồm TEST 2c | PASS (baseline) |
| TC-03 | Optimistic locking stale version | Chỉ client thắng ghi, client thua nhận conflict | PASS bằng worker hai process | PASS (baseline) |
| TC-04 | Cross-project issue access | Thành viên project A bị từ chối với issue project B | PASS service và HTTP integration | PASS (baseline) |
| TC-05 | Snapshot immutability | Sửa issue gốc không đổi release snapshot | PASS snapshot/release-notes test | PASS (baseline) |
| TC-06 | CSRF cho POST | Token thiếu/sai bị từ chối | PASS HTTP integration | PASS (baseline) |
| TC-07 | XSS/escaping | Input không được render thành HTML/JS | `h()` dùng `htmlspecialchars`; chưa chạy browser | STATIC REVIEW / NOT RUN |
| TC-08 | Audit append-only | UPDATE/DELETE audit bị từ chối | Trigger migration 006 tồn tại; chưa xác minh role thật | STATIC REVIEW / NOT RUN |
| TC-09 | Runtime bypass release gate | Runtime role không thể tự bypass | Chưa chứng minh vì custom GUC có thể do connection đặt | BLOCKED |
| TC-10 | HTTP integration suite | Các test HTTP chạy trên schema riêng | 20/20 PASS ở baseline; assertion graph mới chưa rerun | PASS baseline / NOT RUN mới |

### Năm yêu cầu bắt buộc

| Yêu cầu | Đánh giá hiện tại |
|---|---|
| Cycle phải bị từ chối | PASS runtime trong bộ 51 service/database test |
| Blocker chưa retest phải ngăn release | PASS runtime, gồm TEST 2c sau khi sửa fixture |
| Optimistic locking phải ngăn lost update | PASS runtime bằng hai process |
| Cross-project access phải bị từ chối | PASS runtime trong service và HTTP integration |
| Snapshot cũ phải bất biến | PASS runtime trong snapshot/release notes test |

## IV. Tổng hợp kết quả

| Trạng thái | Số lượng |
|---|---:|
| PASS runtime | 71 |
| FAIL runtime | 0 |
| BLOCKED | Database privilege production và browser recheck trong shell hiện tại |
| NOT RUN | Không chạy lại được trong shell hiện tại |
| STATIC REVIEW | Các giới hạn security/database ngoài phạm vi test |
| PARTIAL | Production readiness |

71 PASS là kết quả runtime baseline được cung cấp từ PHP 8.5.4/PostgreSQL 18.6;
không phải suy luận chỉ từ việc test case tồn tại trong source. Các assertion
graph bổ sung sau đó cần được chạy lại trong runtime trước khi nâng baseline.

## V. Danh sách vấn đề phát hiện

### BUG-SEC-006 — Critical — Ranh giới Release Gate chưa đáng tin cậy

- **Module:** Database release lifecycle.
- **Mô tả:** migration 006 cho phép trigger coi transition là hợp lệ khi
  `current_setting('rt.release_transition', true) = 'service'`. Application đặt
  custom GUC bằng `set_config()`, nhưng chưa có database identity riêng chứng minh
  caller được ủy quyền. Nếu runtime role có thể tự đặt GUC và có quyền cập nhật
  cần thiết, direct SQL bypass vẫn chưa bị loại trừ.
- **Expected:** Chỉ release executor function được ủy quyền mới có thể thực hiện
  transition.
- **Actual:** Chưa xác minh role/owner/membership/SET ROLE; custom GUC chưa tạo
  ranh giới tin cậy độc lập.
- **Bằng chứng:** `database/migrations/006_runtime_guards.sql`, `src/App.php`.
- **Hướng xử lý:** Chờ DBA cung cấp cấu hình; sau đó thiết kế Dedicated `NOLOGIN`
  Release Executor với `SECURITY DEFINER`, `search_path` an toàn, quyền tối thiểu
  và revoke EXECUTE mặc định từ PUBLIC. Không tự tạo migration 007 trong lượt này.

### BUG-ENV-001 — Resolved for reported run — Thiếu runtime toolchain trong phiên trước

- **Module:** Test execution.
- **Mô tả:** Không có `php`, `psql`; Docker daemon không sẵn sàng; WSL bị
  `E_ACCESSDENIED`.
- **Hệ quả trước đây:** Không chạy được service, database integration, HTTP
  integration, browser hoặc concurrency test.
- **Kết quả cập nhật:** Runtime report đã xác nhận PHP 8.5.4, PostgreSQL 18.6 và
  cả hai bộ test exit code 0. Phiên shell hiện tại của agent vẫn thiếu executable,
  nên không tự tái hiện được lần chạy đó.

Không ghi nhận thêm bug runtime từ kết quả 71/71. Các giới hạn production
privileges vẫn là blocker độc lập, không được đánh dấu PASS theo test ứng dụng.

## VI. Những phần còn giới hạn

- Database privilege, owner, membership, `INHERIT`, `SET ROLE` và direct SQL
  bypass chưa được DBA xác nhận.
- Audit trigger chưa được đo dưới runtime role production thực tế.
- Dependency Graph chưa có browser screenshot/automation trong shell hiện tại.
- Performance với project/graph lớn chưa được benchmark.

Kết quả 71/71 là runtime result được cung cấp từ môi trường PHP 8.5.4/
PostgreSQL 18.6; agent không tự chạy lại được vì shell hiện tại thiếu executable.

## VII. Đánh giá mức độ hoàn thiện

| Tiêu chí | Đánh giá |
|---|---|
| Chức năng nghiệp vụ | Có bằng chứng runtime baseline 71/71 |
| Tính toàn vẹn dữ liệu | Transaction/lock/FK/guard có test baseline; quyền production chưa xác minh |
| Phân quyền ứng dụng | Project isolation và CSRF PASS baseline; privilege database chờ DBA |
| Xử lý đồng thời | Optimistic locking hai process PASS baseline |
| Kiểm soát phát hành | Release gate/snapshot PASS baseline; database trust boundary BLOCKED |
| Kiểm thử tự động | 51 service/DB và 20 HTTP PASS baseline; assertion graph mới NOT RUN |
| Giao diện | Server-rendered UI có baseline HTTP test; browser graph chưa kiểm tra |
| Production readiness | Chưa đạt, chờ DBA và rerun regression graph |

## VIII. Kế hoạch kiểm thử tiếp theo

1. Cung cấp PHP 8.1+ có `pgsql`, PostgreSQL test riêng và cấu hình kết nối đã
   xác nhận không phải production.
2. Chạy `php tests/run.php` và `php tests/integration.php`; lưu stdout/stderr và
   mã thoát, không sửa test để làm PASS.
3. Chạy các nhóm concurrency, snapshot, dependency và project isolation trên
   schema test mà bootstrap tự tạo.
4. Kiểm tra browser/UI sau khi có PHP built-in server.
5. DBA thực hiện inventory read-only bằng `tools/audit-007-pr1.sql` trên database
   đã xác nhận cô lập.
6. Chỉ sau khi DBA hoàn tất ranh giới quyền mới thiết kế release executor và
   chạy permission regression.

## IX. Dependency Graph đã triển khai

Trang graph được tích hợp vào route hiện có `public/dependencies.php`, không tạo
schema hoặc API mới.

- Dữ liệu node/edge lấy từ các query project-scoped và
  `DependencyService::listForProject()`, `blockedForProject()` và
  `projectCycle()`.
- Chiều edge là `issue_id -> depends_on_id`, đúng với quy ước “A phụ thuộc B”.
- Có summary cards, tìm kiếm theo ID/title, lọc status/priority/blocked/completed,
  empty state và bảng dependency fallback.
- SVG hiển thị status, priority, blocked state, mũi tên và cycle warning hiện có.
- Có zoom in/out, fit/reset, chọn node bằng chuột/bàn phím, highlight edge và
  detail panel với dependency/dependent trực tiếp cùng link tới issue.
- Backend vẫn gọi `requireProjectAccess()` trước khi truy vấn; mọi node/edge đều
  bị giới hạn trong project hiện tại.
- CSS responsive cho desktop/mobile; không tải thư viện/CDN bên ngoài.

**Xác minh hiện tại:** JavaScript graph đã parse thành công bằng Node static
check. Đã bổ sung assertion HTTP cho SVG/detail, project isolation, chiều edge
và empty filter state vào `tests/integration.php`. Phiên shell này không có PHP
để chạy lại suite, nên các assertion mới là NOT RUN; browser testing, console,
network, responsive desktop/tablet/mobile và screenshot đều BLOCKED.

**Không có external dependency:** static review `public/` không cho thấy CDN hoặc
thư viện graph bên ngoài; graph dùng SVG và JavaScript nội bộ.

## X. Kết luận

Theo kết quả baseline runtime được cung cấp, ReleaseTracker đạt 71/71 test: 51
service/database test và 20 HTTP integration test, trong đó TEST 2c đã PASS sau
khi sửa fixture đúng phạm vi. Optimistic locking cạnh tranh, dependency
cycle/blocker, project isolation, snapshot, release notes, CSRF và HTTP
authorization đều có bằng chứng runtime tại thời điểm baseline.

Dependency Graph đã được triển khai trên route hiện có với dữ liệu thật,
project isolation, lọc, tìm kiếm, detail và fallback HTML. Regression test và
browser verification sau các chỉnh sửa graph hiện tại vẫn phải chạy lại trong
runtime có PHP/PostgreSQL.

Release Gate vẫn chưa được coi là production-secure. Migration 006 còn phụ thuộc
custom GUC `rt.release_transition`; Dedicated `NOLOGIN Release Executor` và
`SECURITY DEFINER` vẫn chờ DBA. Không tạo migration 007, role/function hoặc thay
đổi schema/quyền database trong task này.
