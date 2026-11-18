# Bộ phép thử ReleaseTracker (v1.1.0)

Tài liệu này là "đáp án" cho các phép thử bắt buộc của Đề tài 5, hai tình huống sử dụng của đề và các lỗi nghiệp vụ đã sửa ở v1.1.0. Cột **Test tự động** là tên `testCase(...)` tương ứng; chạy tất cả bằng `bash lab.sh test`.

- `tests/run.php`: unit test thuần, không cần CSDL (mã `U*`).
- `tests/integration.php`: test nghiệp vụ trên CSDL; mỗi test chạy trong `BEGIN … ROLLBACK`, tự tạo dự án/công việc riêng, không phụ thuộc id cố định (mã `T*`, `R*`, `S*`).
- `tests/regression.php`: bộ hồi quy cũ (51+ test, gồm test đồng thời bằng nhiều tiến trình).
- `tests/http.php`: smoke test HTTP qua PHP built-in server (đăng nhập, CSRF, phân quyền, trang).

Tài khoản dùng trong test: `dev_a` (developer), `tester_a` (tester), `release_a` (release manager), `admin_a` (admin), `dev_b` (developer của dự án Mobile Client, đóng vai "người dự án B").

## 1. Năm phép thử bắt buộc

| Mã | Dữ liệu đầu vào | Các bước | Kết quả mong đợi | Test tự động |
| --- | --- | --- | --- | --- |
| T1 | Dự án mới với 3 công việc A, B, C | 1. Thêm "A phụ thuộc B". 2. Thêm "B phụ thuộc C". 3. Thêm "C phụ thuộc A". | Bước 3 bị từ chối (`CycleException`), thông báo chứa đúng chuỗi `#A → #B → #C → #A`; bảng `dependencies` vẫn chỉ có 2 dòng; không có audit `dependency_added` cho cạnh C→A. Giao diện tô đậm từng mắt xích. | `T1: vòng A→B→C→A bị từ chối, chỉ rõ chuỗi và không ghi gì` |
| T2 | Phiên bản planned chứa một công việc blocker | 1. `check()` khi blocker chưa có test. 2. Tester ghi `failed`, `check()` lại. 3. Thử `snapshot()`. 4. Tester ghi `passed` (status → verified), `check()` lại. | Bước 1, 2: điều kiện "Blocker đã kiểm thử lại hợp lệ" chưa đạt; bước 3 ném exception (không chốt được); bước 4: điều kiện blocker đạt. | `T2: blocker chưa test/failed thì không chốt được, passed + verified thì đạt` |
| T3 | Một công việc ở version v | 1. Người 1 update với version v. 2. Người 2 update với cùng version v. | Lần 2 ném `VersionConflictException` (thông báo "XUNG ĐỘT"); dữ liệu trong DB là của người 1; version = v+1. | `T3: hai lần update cùng version, lần sau báo xung đột, dữ liệu lần đầu còn nguyên` |
| T4 | Công việc và phiên bản đã chốt của dự án A; `dev_b` chỉ thuộc dự án B | `dev_b` lần lượt: update, bình luận, ghi test, thêm phụ thuộc, xuất ghi chú phát hành của dự án A | Mọi thao tác ném `ForbiddenException`/`NotFoundException`; dữ liệu dự án A không đổi; không có audit mới. | `T4: thành viên dự án B không thao tác được trên dự án A` |
| T5 | Phiên bản đã chốt có snapshot | 1. Sửa tiêu đề và trạng thái công việc nguồn. 2. Thử thêm item mới. 3. Thử gỡ item. | `release_items` giống hệt trước khi sửa; ghi chú phát hành không đổi; thêm/gỡ item đều bị từ chối. | `T5: sau khi chốt, sửa công việc không đổi snapshot; không thêm/gỡ item` |

## 2. Hai tình huống sử dụng của đề

| Mã | Dữ liệu đầu vào | Các bước | Kết quả mong đợi | Test tự động |
| --- | --- | --- | --- | --- |
| S1 | Dự án mới, tester và developer là thành viên | 1. Tester báo lỗi (bug, có bước tái hiện). 2. Phân công cho developer. 3. Developer sửa, gắn link commit, chuyển "Đã sửa". 4. Tester ghi `failed` → công việc về "Đang xử lý". 5. Developer sửa lại → "Đã sửa". 6. Tester ghi `passed`. | Trạng thái lần lượt open → resolved → in_progress → resolved → verified; lịch sử thay đổi có đủ các bước, giá trị cũ → mới. | `S1: lỗi → phân công → sửa → test lại → xác nhận` |
| S2 | Dự án mới, 2 công việc đã verified (1 tính năng, 1 lỗi) | 1. Release manager lập phiên bản. 2. Thêm 2 công việc. 3. "Xác nhận sẵn sàng" (check đạt, planned → ready). 4. Chốt phát hành (ready → released). 5. Xuất ghi chú `md` và `txt`. | Checklist đạt 8/8; ghi chú nhóm theo "Tính năng" / "Sửa lỗi", có #id, tiêu đề, người phụ trách, kết quả kiểm thử khi chốt, link commit/PR. | `S2: lập phiên bản → kiểm tra → chốt → xuất ghi chú` |

## 3. Hồi quy cho các lỗi đã sửa ở Phần 2

| Mã | Lỗi cũ | Dữ liệu và bước | Kết quả mong đợi | Test tự động |
| --- | --- | --- | --- | --- |
| R1 | Developer hạ blocker xuống low là bản phát hành qua kiểm tra | Blocker trong phiên bản planned. a) Developer đổi ưu tiên → low. b) Release manager đổi → low, rồi công việc được đóng mà không test lại. | a) Bị từ chối, ưu tiên giữ blocker. b) Có audit `priority_changed` (cũ/mới); `check()` vẫn coi là blocker ("từng là blocker kể từ khi gắn vào phiên bản") và chưa đạt. | `R1: hạ ưu tiên blocker để lách bị chặn và vẫn bị coi là blocker` |
| R2 | Blocker có test passed cũ, bị mở lại vẫn qua | Blocker được test passed, sau đó developer mở lại (open). | Điều kiện blocker chưa đạt; `markReady()` bị từ chối. | `R2: blocker passed rồi bị mở lại thì không đạt` |
| R3 | Failed chuyển công việc về retest | Tester ghi lần lượt failed, blocked, passed. | failed/blocked → in_progress; passed → verified; ghi test với version cũ báo xung đột. | `R3: failed/blocked trả về in_progress, passed thành verified, test dùng version` |
| R4 | Hai người chốt cùng lúc | Phiên bản ready; hai lệnh `release()` liên tiếp (bản đồng thời thật bằng 2 tiến trình nằm ở `regression.php` – `TEST 5b`). | Chỉ lần đầu thành công; lần sau báo "đã được chốt"; đúng 1 audit `release_snapshotted`. | `R4: hai lần chốt cùng phiên bản chỉ một lần thành công` |
| R5 | Phụ thuộc khác dự án | Thêm/gỡ phụ thuộc giữa công việc dự án A và dự án B. | Bị từ chối, không ghi gì. | `R5: phụ thuộc khác dự án bị từ chối khi thêm và gỡ` |
| R6 | Developer tự đặt verified | Developer gửi form cập nhật với status = verified. | Bị từ chối; chỉ ghi test passed mới chuyển sang verified. | `R6: developer không tự đặt verified qua form cập nhật` |
| R7 | Phiên bản ready không tự hạ khi công việc bị sửa | Phiên bản ready; developer chuyển một công việc về in_progress. | Phiên bản tự về planned; không chốt được. | `R7: phiên bản ready tự hạ về planned khi công việc bị sửa` |

## 4. Unit test không cần CSDL

| Mã | Nội dung | Test tự động |
| --- | --- | --- |
| U1 | `DependencyService::findPath` trên mảng kề (có đường, không có đường, có vòng sẵn không lặp vô hạn) | `U1: findPath tìm đường bằng DFS trên mảng kề` |
| U2 | `DependencyService::findCycle` phát hiện/không phát hiện vòng | `U2: findCycle phát hiện chu trình` |
| U3 | `DependencyService::cycleIfAdded` trả chuỗi `[A, B, C, A]` và `formatChain` ra `#A → #B → #C → #A` | `U3: cycleIfAdded và formatChain cho chuỗi gây vòng` |
| U4 | Các hàm `*Label()` trả tiếng Việt, giá trị lạ trả nguyên, rỗng trả "—" | `U4: các hàm *Label trả nhãn tiếng Việt` |
| U5 | `validateEnum` chấp nhận giá trị hợp lệ, từ chối giá trị sai bằng thông báo tiếng Việt, không lộ lỗi CSDL | `U5: validateEnum kiểm tra enum bằng thông báo tiếng Việt` |
| U6 | `History::describe` mô tả audit (cũ → mới, nhãn tiếng Việt) | `U6: History::describe mô tả lịch sử thay đổi` |
| U7 | `ReleaseNotes::renderMarkdown/renderText` nhóm theo loại | `U7: ghi chú phát hành nhóm theo loại công việc` |

## 5. Kiểm thử thủ công trên giao diện (gợi ý demo)

1. Đăng nhập `dev_a` → Phụ thuộc → thêm quan hệ tạo vòng → thấy thông báo và chuỗi mắt xích tô đậm.
2. Mở cùng một công việc ở hai tab, lưu tab 1 rồi lưu tab 2 → bảng so sánh xung đột, nút "Tải bản mới nhất" và "Ghi đè có chủ đích".
3. Đăng nhập `release_a` → Phiên bản `1.0.0` → checklist có ✗ ở điều kiện blocker kèm link tới công việc; nút "Chốt phát hành" chỉ hiện khi ở trạng thái Sẵn sàng.
4. Phiên bản `0.9.0` (đã chốt sẵn trong seed) → sửa một công việc của nó → ghi chú phát hành không đổi.
