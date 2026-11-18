# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/); đánh số theo [Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-11-18

### Sửa lỗi nghiệp vụ

- **Hạ ưu tiên để lách điều kiện phát hành:** developer hạ blocker xuống low là phiên bản qua kiểm tra. Nay chỉ tester, release manager, admin được đổi ưu tiên của công việc đang là blocker; mỗi lần đổi ưu tiên ghi audit `priority_changed` (cũ/mới); `ReleaseService::check` coi công việc là blocker nếu ưu tiên hiện tại là blocker **hoặc từng là blocker kể từ khi gắn vào phiên bản**.
- **Blocker bị mở lại vẫn qua kiểm tra:** blocker chỉ đạt khi trạng thái hiện tại là verified/closed, kết quả kiểm thử mới nhất là passed, làm trên đúng phiên bản dữ liệu hiện tại (không có lần sửa nào sau đó) và có bằng chứng nguồn gốc.
- **Kết quả failed/blocked chuyển công việc về `retest`:** nay trả về `in_progress` cho người sửa; passed chuyển `verified`. Form ghi kết quả kiểm thử gửi kèm version để phát hiện xung đột.
- **Chốt phát hành đồng thời:** `check()` và ghi snapshot chạy trong cùng transaction với `SELECT … FOR UPDATE` trên dòng `releases`; kiểm tra số dòng bị UPDATE, bằng 0 thì ROLLBACK.
- **Thêm phụ thuộc đồng thời có thể cùng tạo vòng:** dùng `pg_advisory_xact_lock(project_id)` khi thêm/gỡ phụ thuộc; `DependencyService::remove` kiểm tra quyền và hai công việc cùng dự án.
- Lỗi (bug) bắt buộc có bước tái hiện; trùng tiêu đề trong dự án báo bằng tiếng Việt thay vì lỗi CSDL.
- Giá trị enum (ưu tiên, trạng thái, loại, kết quả kiểm thử, vai trò) được kiểm tra ở PHP bằng hằng dùng chung, báo lỗi tiếng Việt kèm danh sách lựa chọn.
- Sửa ~150 chuỗi tiếng Việt bị hỏng mã hoá trong bộ test.

### Thêm mới

- Trang chi tiết công việc: **Lịch sử thay đổi** (ai đổi gì, cũ → mới, đọc từ audit_log) và danh sách **phiên bản đã chốt** từng chứa công việc.
- `public/release-notes-export.php?id=…&format=md|txt`: tải ghi chú phát hành từ snapshot, nhóm theo Tính năng / Sửa lỗi / Công việc, có #id, tiêu đề, người phụ trách, kết quả kiểm thử khi chốt, link commit/PR. Migration `007` thêm `release_items.snapshot_issue_type`.
- `IssueService::changePriority`, `DependencyService::cycleIfAdded` (hàm thuần), `src/Labels.php`, `src/History.php`.
- Dữ liệu mẫu: bình luận, kết quả kiểm thử passed/failed/blocked có audit, link commit/PR, phiên bản cho Mobile Client, phiên bản 0.9.0 đã chốt có snapshot.

### Giao diện

- Mọi giá trị enum hiển thị bằng nhãn tiếng Việt; badge màu theo mức (blocker đỏ, critical cam, high vàng, medium xám, low xanh; verified/closed xanh lá).
- Thanh "Dự án đang chọn" có ô chọn dự án, nhớ trong session.
- Danh sách công việc: lọc theo trạng thái, ưu tiên, người phụ trách, loại, tìm theo tiêu đề/#id, nút "Việc của tôi" (URL GET giữ được bộ lọc).
- Post/Redirect/Get + flash cho mọi form; lỗi thì giữ lại dữ liệu đã nhập.
- Xung đột chỉnh sửa: bảng so sánh bản vừa nhập và bản trong CSDL, nút "Tải bản mới nhất" và "Ghi đè có chủ đích".
- Trang phiên bản: checklist ✓/✗ có link tới công việc gây chặn, điều kiện công bố công khai, nút "Xác nhận sẵn sàng", hộp xác nhận chốt bằng `<dialog>`.
- Trang phụ thuộc: chọn công việc bằng dropdown có tiêu đề, "Đang chặn tiến độ" nhóm theo công việc bị chặn, tô đậm từng mắt xích khi bị từ chối vì vòng.
- Form tạo lỗi có mẫu "Bước tái hiện / Kết quả mong đợi / Kết quả thực tế"; chỉ hiện form/nút theo quyền.
- Tổng quan: lỗi mở theo ưu tiên, số việc bị chặn, phiên bản sắp tới và tình trạng sẵn sàng.
- Empty state, bảng cuộn ngang trên điện thoại, label cho mọi input, trạng thái focus rõ.

### Kiểm thử

- Tổ chức lại: `tests/run.php` (unit, không cần DB), `tests/integration.php` (T1–T5, hồi quy R1–R7, tình huống S1–S2; mỗi test trong `BEGIN … ROLLBACK`), `tests/regression.php` (bộ hồi quy cũ + đồng thời), `tests/http.php` (smoke HTTP).
- `bash lab.sh test` chạy tất cả, in tổng PASS/FAIL, exit khác 0 nếu có test hỏng.
- `docs/test-cases.md`: bảng phép thử và test tự động tương ứng.

### Tài liệu

- README viết lại (cài đặt, tài khoản demo, kịch bản demo, điều kiện phát hành, giới hạn), `docs/architecture.md`, `NOTICE.md`, `CHANGELOG.md`, mẫu issue/PR trong `.github/`, `.local/db.example.json`.

## [1.0.0] — 2026-11-02

Bản MVP.

### Có trong bản này

- Đăng nhập, phân quyền theo dự án (developer, tester, release_manager, admin), CSRF cho mọi POST.
- Lỗi/công việc: tạo, sửa có khoá lạc quan (cột `version`), bình luận, kết quả kiểm thử gắn version và bằng chứng nguồn gốc trong audit.
- Phụ thuộc: từ chối tự phụ thuộc, trùng lặp, khác dự án và chu trình (DFS); danh sách việc bị chặn; đồ thị.
- Phiên bản: planned → ready → released, kiểm tra điều kiện phía server, snapshot bất biến (trigger CSDL), ghi chú Markdown/TXT.
- Migration 001–006, seed 2 dự án / 40 công việc / 15 phụ thuộc / 3 phiên bản, audit log chỉ ghi thêm.
- 51 test service/DB và 20 test HTTP.

### Lỗi đã biết của bản này (sửa ở 1.1.0)

- Developer hạ ưu tiên blocker để phiên bản qua kiểm tra.
- Kết quả failed đưa công việc về `retest` thay vì trả lại người sửa.
- Giao diện in giá trị enum thô (tiếng Anh), chưa có bộ lọc, chưa có lịch sử thay đổi trên trang công việc, xung đột chỉ báo lỗi không cho so sánh.
- Một số chuỗi tiếng Việt trong test bị hỏng mã hoá.

[1.1.0]: https://github.com/[ten-nhom]/releasetracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/[ten-nhom]/releasetracker/releases/tag/v1.0.0
