# BÁO CÁO TIẾN ĐỘ KỸ THUẬT RELEASETRACKER

**Tên dự án:** ReleaseTracker  
**Công nghệ:** PHP, PostgreSQL, giao diện server-rendered bằng PHP/CSS  
**Trạng thái:** Chờ DBA xác nhận cấu hình quyền database

## 1. Phạm vi và cách phân loại

Báo cáo này được đối chiếu với source code, migration, seed, controller và test
hiện có trong repository. Các mức trạng thái được dùng như sau:

- **Đã triển khai:** logic hoặc tài liệu đã tồn tại trong source.
- **Đã kiểm tra tĩnh:** đã đọc/đối chiếu code hoặc test, nhưng chưa thực thi runtime.
- **Chưa xác minh runtime:** chưa có kết quả chạy thực tế trên PHP, PostgreSQL,
  trình duyệt hoặc database test.

Không thay đổi dữ liệu production trong quá trình rà soát.

## 2. Tóm tắt

ReleaseTracker có các module quản lý project, issue, dependency, test result và
release. Business rule chính nằm trong `src/*Service.php`, HTTP entry point nằm
trong `public/*.php`, còn schema/migration/seed nằm trong `database/`.

Các phần đã được rà soát gồm optimistic locking, project isolation, dependency
cycle, release gate và release snapshot. Repository có test cho các luồng này,
nhưng môi trường hiện tại không có lệnh `php` trong `PATH`, nên chưa thể tuyên bố
test đã PASS runtime.

Blocker chính là ranh giới quyền của release gate ở PostgreSQL. Migration 006
hiện kiểm tra transaction-local custom GUC
`rt.release_transition = 'service'`. Chưa có bằng chứng rằng application runtime
role không thể tự đặt giá trị này hoặc có quyền owner/bypass tương đương. Vì vậy
migration 006 chưa được coi là cơ chế bảo mật production hoàn chỉnh.

## 3. Các hạng mục đã triển khai và rà soát

### 3.1. Optimistic locking

`IssueService::update()` đọc version hiện tại và cập nhật bằng điều kiện
`id`, `project_id`, `version`, đồng thời tăng version. Khi version không khớp
hoặc không có dòng được cập nhật, service ném `ConflictException`.

Form trong `public/issues.php` gửi version hiện tại. Khi xảy ra conflict, giao
diện đã có liên kết tải lại dữ liệu issue mới nhất.

**Mức xác minh:** đã triển khai và đã kiểm tra tĩnh; chưa chạy concurrency test
hoặc kiểm tra trình duyệt runtime.

### 3.2. Dependency management

`DependencyService` giới hạn dependency trong cùng project và từ chối
self-dependency, dependency trùng hoặc tạo cycle. Service có các hàm tìm đường
đi, phát hiện cycle và lấy issue bị chặn. Release gate duyệt dependency closure,
không chỉ dependency trực tiếp.

**Mức xác minh:** đã kiểm tra tĩnh `findPath()`, `findCycle()`, `projectCycle()`,
`blockedForProject()` và các query project-scoped; chưa chạy database runtime.

### 3.3. Release gate và snapshot

`ReleaseService::check()` kiểm tra release item, project, status, blocker,
dependency, cycle, snapshot và role. Issue trong release phải đáp ứng trạng thái
phù hợp và test result đúng version.

Khi chốt release, snapshot lưu title, status, priority, assignee, description,
commit URL, test result và metadata version; tên project được lưu vào
`project_name_snapshot`. Release đã chốt được hiển thị như snapshot lịch sử.

**Mức xác minh:** đã kiểm tra tĩnh service và transaction; chưa xác minh tính bất
biến snapshot hoặc gate bằng database runtime.

### 3.4. Migration 006 và audit guard

Migration 006 đã có:

- trigger từ chối UPDATE/DELETE trên `audit_log`;
- trigger kiểm soát một số thay đổi lifecycle release;
- REVOKE các quyền lifecycle/audit áp dụng cho non-owner role;
- application service gọi `authorizeReleaseTransition()` trước transition cần thiết.

Các giới hạn chưa được giải quyết:

- trigger tin vào custom GUC do connection đặt;
- chưa có dedicated `NOLOGIN` release executor sở hữu `SECURITY DEFINER FUNCTION`;
- chưa xác nhận runtime role không phải member, không inherit và không `SET ROLE`
  được sang release executor;
- PostgreSQL owner vẫn có quyền ngầm định, nên REVOKE từ PUBLIC không thay thế
  việc dùng runtime role tách biệt.

Migration 006 được giữ nguyên để chờ DBA. Không kết luận database release gate đã
an toàn cho production.

### 3.5. UX và tài liệu thiết kế

Đã bổ sung xử lý conflict tại `public/issues.php`, cung cấp liên kết tải lại dữ
liệu mới nhất khi gặp `ConflictException`.

Đã tạo `docs/UI-DESIGN.md`, mô tả thiết kế Dependency Graph và Release Readiness
Dashboard, gồm nguồn dữ liệu service hiện có, project isolation, error state,
HTML fallback, accessibility và tiêu chí nghiệm thu. Hai chức năng này mới ở mức
thiết kế, chưa triển khai UI hoàn chỉnh.

## 4. Tình trạng kiểm thử

### 4.1. Công cụ và runtime

- Không tìm thấy `php` trong `PATH` hiện tại.
- Chưa có kết quả chạy PostgreSQL runtime trên database test riêng.
- Chưa chạy được `tests/run.php`, `tests/integration.php` hoặc HTTP/browser test.

### 4.2. Bảng trạng thái

| Hạng mục | Trạng thái | Giới hạn |
|---|---|---|
| Optimistic locking | Đã kiểm tra tĩnh | Chưa xác minh race runtime |
| Conflict UI | Đã triển khai, kiểm tra tĩnh | Chưa xác minh trình duyệt |
| Dependency cycle | Có code/test, kiểm tra tĩnh | Chưa chạy runtime |
| Blocker/dependency closure | Kiểm tra tĩnh | Chưa xác minh database state |
| Project isolation | Kiểm tra tĩnh | Chưa chạy HTTP/database |
| Release snapshot | Kiểm tra tĩnh | Chưa xác minh bất biến runtime |
| Release gate | Kiểm tra tĩnh | Chưa xác minh enforcement bằng role thật |
| Audit trigger | Đã có code | Chưa xác minh quyền owner/runtime |
| Dependency Graph | Đã có thiết kế | Chưa triển khai UI |
| Readiness Dashboard | Đã có thiết kế | Chưa triển khai UI |

Không ghi nhận hạng mục nào là **PASS runtime** khi chưa thực sự chạy và lưu kết
quả xác minh.

## 5. Blocker và thông tin cần DBA

Định hướng đã thống nhất là **Dedicated NOLOGIN Release Executor** với ba nhóm
quyền:

1. **Migration/Schema Owner:** quản lý schema và chạy migration.
2. **Application Runtime:** phục vụ nghiệp vụ thông thường, không tự cập nhật
   release lifecycle.
3. **Release Executor:** role `NOLOGIN` sở hữu function phát hành được ủy quyền,
   chỉ có quyền tối thiểu cần thiết.

DBA cần xác nhận:

- PostgreSQL version và database name;
- schema owner, migration role và application runtime role;
- release executor role dự kiến;
- membership, `INHERIT`, `SET ROLE` và khả năng kế thừa giữa các role;
- quyền `CREATEROLE`, `GRANT` và `REVOKE`;
- database test riêng cho migration và permission regression.

Runtime role không được là member của release executor, không được `SET ROLE`
sang role đó và chỉ được `EXECUTE` các function cần thiết. `SECURITY DEFINER`
function phải có `search_path` an toàn và thu hồi EXECUTE mặc định từ PUBLIC.
Xác thực người dùng Release Manager vẫn phải thực hiện ở PHP; không coi
`user_id` do PHP truyền vào là danh tính đã được PostgreSQL xác thực.

## 6. Rủi ro còn tồn tại

- Release gate chưa có database identity đủ tin cậy để chống bypass bằng SQL trực
  tiếp.
- Quyền thật của runtime role, schema owner và migration role chưa được xác minh.
- Chưa có runtime result cho concurrency, snapshot immutability, trigger,
  project isolation và regression.
- Dependency Graph và Readiness Dashboard chưa được triển khai.
- Chưa đủ cơ sở kết luận production readiness hoặc production security.

## 7. Kế hoạch tiếp theo

### Giai đoạn 1: Chờ và xác minh DBA

Thu thập role, ownership, membership, quyền GRANT/REVOKE và database test. Không
tạo migration 007, role hoặc SECURITY DEFINER function trước khi có xác nhận.

### Giai đoạn 2: Release Executor

Sau khi DBA xác nhận, thiết kế role `NOLOGIN`, function `SECURITY DEFINER` với
`search_path` an toàn, quyền tối thiểu, revoke EXECUTE từ PUBLIC và kiểm tra
runtime role không thể bypass gate.

### Giai đoạn 3: Regression runtime

Chạy trên database test riêng: dependency cycle; blocker/dependency chưa đạt;
optimistic-lock conflict; project isolation; snapshot sau phát hành; runtime role
bypass; UPDATE/DELETE audit log. Chỉ đánh dấu PASS khi test thực sự chạy.

### Giai đoạn 4: Chức năng mở rộng

Sau khi yêu cầu bảo mật và nghiệp vụ cốt lõi được xác minh, triển khai Dependency
Graph, Release Readiness Dashboard và dữ liệu demo theo `docs/UI-DESIGN.md`.

## 8. Kết luận

Source hiện có cơ sở cho optimistic locking, dependency validation, release gate,
release snapshot và audit guard; đồng thời đã có xử lý conflict ở giao diện và
tài liệu thiết kế hai module mở rộng. Các kết luận này chỉ ở mức đã triển khai
hoặc đã kiểm tra tĩnh.

Môi trường chưa chạy được PHP/PostgreSQL runtime. Migration 006 chưa được coi là
cơ chế bảo mật production hoàn chỉnh vì còn phụ thuộc custom GUC và cấu hình
quyền chưa được DBA xác nhận.

**TRẠNG THÁI DỰ ÁN: CHỜ DBA XÁC NHẬN QUYỀN DATABASE.**

Không kết luận production security an toàn, không tạo migration 007 và không thay
đổi cơ chế phân quyền database cho đến khi blocker về role, ownership và ranh
giới tin cậy được giải quyết.
