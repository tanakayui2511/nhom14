# NHIỆM VỤ: NÂNG CẤP TOÀN DIỆN DỰ ÁN RELEASETRACKER

Bạn là Senior Software Engineer, Software Architect và QA Engineer. Hãy trực tiếp kiểm tra, sửa chữa, nâng cấp và kiểm thử dự án ReleaseTracker ngay trong workspace hiện tại.

**Không chỉ phân tích hoặc đề xuất. Hãy thực sự chỉnh sửa mã nguồn, bổ sung test, chạy kiểm thử trong môi trường khả dụng và báo cáo kết quả thực tế.**

Tuy nhiên, không được sửa hàng loạt một cách thiếu kiểm soát. Hãy đọc code hiện có, xác định nguyên nhân, thực hiện theo từng giai đoạn, kiểm thử sau mỗi nhóm thay đổi và giữ nguyên những chức năng đang hoạt động tốt.

---

# I. BỐI CẢNH VÀ RÀNG BUỘC

## 1. Công nghệ

- PHP 8.1 trở lên, PHP thuần.
- PostgreSQL.
- Không sử dụng framework PHP nặng.
- Không thêm Composer hoặc autoload nếu không thực sự cần thiết.
- Giữ khả năng chạy bằng PHP built-in server.
- Môi trường chính: Ubuntu/WSL.
- Ưu tiên code đơn giản, có thể giải thích được khi vấn đáp.

Không tự ý chuyển sang Laravel, Symfony hoặc một framework khác. Không xây dựng lại dự án từ đầu.

## 2. Mục tiêu dự án

ReleaseTracker là hệ thống theo dõi lỗi và quản lý kế hoạch phát hành phần mềm.

Năm nhóm chức năng phải được hoàn thiện:

1. Quản lý dự án, thành viên, công việc, lỗi, mức ưu tiên và quyền thao tác.
2. Ghi bước tái hiện lỗi, phân công sửa, bình luận và kết quả kiểm thử sau sửa.
3. Quản lý công việc phụ thuộc, phát hiện quan hệ vòng và hiển thị công việc đang chặn tiến độ.
4. Lập phiên bản dự kiến, gắn công việc, kiểm tra điều kiện phát hành.
5. Chốt danh sách thay đổi, lưu lịch sử, xuất ghi chú phát hành và phát hiện xung đột cập nhật.

## 3. Những yêu cầu kỹ thuật không được bỏ qua

### Yêu cầu A — Dependency graph và điều kiện phát hành

- Tự triển khai logic kiểm tra đồ thị phụ thuộc bằng PHP, không thay bằng thư viện đồ thị ngoài.
- Từ chối quan hệ phụ thuộc tạo chu trình.
- Trả về đường đi cụ thể gây ra chu trình.
- Hiển thị các công việc đang bị chặn.
- Không cho release đạt trạng thái sẵn sàng nếu còn lỗi mức chặn chưa được kiểm tra lại hợp lệ.
- Điều kiện phát hành phải được công bố, hiển thị và được kiểm tra ở phía server.
- Không chỉ kiểm tra ở UI.

### Yêu cầu B — Optimistic locking và snapshot

- Khi hai người cùng mở một issue, người lưu bằng phiên bản dữ liệu cũ phải nhận được thông báo xung đột.
- Không âm thầm ghi đè dữ liệu mới.
- Khi phát hành, phải lưu snapshot của danh sách thay đổi.
- Sau khi release đã được chốt, việc sửa issue nguồn không được làm thay đổi snapshot hoặc release notes cũ.
- Hai request cùng chốt một release không được cùng thành công hoặc ghi đè snapshot của nhau.

## 4. Dữ liệu phải được bảo toàn

Seed hiện tại theo báo cáo gồm:

- 2 dự án: ReleaseTracker Core và Mobile Client.
- 40 issue/công việc.
- 15 quan hệ phụ thuộc.
- 3 release cho ReleaseTracker Core.
- Các tài khoản demo và dữ liệu liên quan.

**Phải kiểm tra dữ liệu thực tế trước khi thay đổi schema. Không xóa database, không drop bảng để tạo lại, không xóa dữ liệu seed đang dùng và không đổi ID của bản ghi hiện có một cách tùy tiện.**

Khi cần thay đổi schema, hãy tạo migration có phiên bản, có kiểm tra tính hợp lệ và không áp dụng lặp gây hỏng dữ liệu.

## 5. Phạm vi và ưu tiên

Mục tiêu là nâng chất lượng kỹ thuật tối đa, với một người phát triển và thời gian trên hai tuần.

Ưu tiên theo thứ tự:

1. Tính đúng đắn và nhất quán dữ liệu.
2. Bảo mật và phân quyền.
3. Hoàn thành năm phép thử bắt buộc.
4. Kiểm thử hồi quy.
5. Hoàn thiện chức năng nghiệp vụ.
6. Migration, kiến trúc, khả năng bảo trì.
7. Dọn code cũ, tài liệu và bàn giao.

---

# II. GIAI ĐOẠN 0 — KIỂM TRA REPOSITORY TRƯỚC KHI SỬA

Trước khi chỉnh sửa bất kỳ file nào:

1. Kiểm tra cấu trúc thư mục và các file quan trọng.
2. Đọc tối thiểu:
   - `src/Database.php`
   - `src/App.php`
   - `src/DependencyService.php`
   - `src/ReleaseService.php`
   - `database/schema.sql`
   - `database/seed.sql`
   - `tests/run.php`
   - `tests/integration.php`
   - `lab.sh`
   - `README.md`
   - Các trang đăng nhập, issue, dependency, release, release notes và công cụ DB.
3. Tìm tất cả chỗ gọi đến các service, hàm xác thực, CSRF, kiểm tra quyền và thao tác ghi database.
4. Đọc code thực tế thay vì chỉ tin vào báo cáo hoặc chú thích.
5. Kiểm tra Git status, branch và thay đổi chưa commit. Không ghi đè hoặc xóa thay đổi có sẵn của tôi.
6. Kiểm tra môi trường hiện tại: phiên bản PHP, PostgreSQL client, kết nối DB và các lệnh test khả dụng.
7. Chạy test hiện có trước khi sửa, nếu môi trường cho phép. Ghi nhận test nào pass, fail, bị chặn bởi môi trường hoặc không thuộc ReleaseTracker.
8. Đối chiếu code hiện có với yêu cầu trong prompt này.

Sau khi khảo sát, hãy tạo một checklist ngắn về các lỗi thực tế tìm được. Tiếp theo, **hãy tiếp tục thực hiện các thay đổi, không dừng lại chỉ để gửi cho tôi bản kế hoạch chờ duyệt**.

Nếu môi trường không thể chạy database hoặc test, tiếp tục sửa code và xây dựng test ở mức có thể xác minh; phải ghi rõ những phần chưa thực sự được chạy.

---

# III. GIAI ĐOẠN 1 — BẢO MẬT VÀ TOÀN VẸN DỮ LIỆU

## 1. Kiểm tra xác thực và phân quyền

Rà soát toàn bộ endpoint có khả năng đọc hoặc sửa dữ liệu.

Bắt buộc:

- Mọi thao tác nhạy cảm phải kiểm tra trạng thái đăng nhập.
- Mọi thao tác sửa dữ liệu phải kiểm tra CSRF.
- Sửa lỗi CSRF fail-open nếu cả token trong session và request đều rỗng.
- Cấu hình session cookie phù hợp: `HttpOnly`, `SameSite` và `Secure` khi môi trường HTTPS yêu cầu.
- Kiểm tra quyền phía server theo dự án và vai trò.
- Không tin `project_id`, `issue_id`, `release_id`, `user_id` hoặc role gửi từ trình duyệt nếu chưa xác minh.
- Kiểm tra quyền trước khi thực hiện thao tác ghi.
- Validate dữ liệu, độ dài chuỗi, trường bắt buộc, enum và ID hợp lệ.
- Sử dụng prepared statements.
- Không hiển thị chi tiết lỗi SQL hoặc thông tin nhạy cảm cho người dùng.
- Không ghi mật khẩu, session token hoặc CSRF token vào audit log.

Kiểm tra đặc biệt các trường hợp người dùng có quyền trên dự án A nhưng gửi trực tiếp request sửa issue, thêm dependency, thêm issue vào release hoặc xem audit log thuộc dự án B.

## 2. Quyền theo vai trò

Rà soát và triển khai quyền theo hướng:

- Developer: quản lý công việc và lỗi trong phạm vi dự án theo chính sách được xác định.
- Tester: ghi nhận kết quả kiểm thử và các thao tác kiểm thử được phép.
- Release manager: kiểm tra điều kiện, quản lý và chốt release.
- Admin: các thao tác quản trị được cấp phép rõ ràng.

Đây là định hướng. Hãy kiểm tra luồng nghiệp vụ hiện tại, xây dựng ma trận quyền nhất quán, rồi thực thi quyền ở phía server.

Không để mọi thành viên có toàn bộ quyền chỉ vì họ thuộc cùng dự án.

## 3. Validation và phản hồi

Kiểm tra và sửa các tình huống:

- Sửa issue với tiêu đề rỗng hoặc vượt giới hạn.
- Gửi comment khi issue không tồn tại hoặc người dùng không có quyền.
- Gửi test result khi issue không tồn tại hoặc thuộc dự án khác.
- Tạo dependency sai dự án hoặc trùng lặp.
- Thao tác release khi release đã chốt.
- ID không hợp lệ, enum không hợp lệ hoặc thiếu dữ liệu bắt buộc.

Không báo thành công nếu thao tác database thất bại hoặc không có bản ghi nào được cập nhật như mong đợi.

---

# IV. GIAI ĐOẠN 2 — NÂNG CẤP DEPENDENCY SERVICE

Kiểm tra và hoàn thiện `DependencyService.php`.

## Yêu cầu

1. Có thuật toán DFS tự viết để phát hiện chu trình.
2. Không cho issue phụ thuộc chính nó.
3. Từ chối thêm quan hệ tạo chu trình.
4. Trả về đường đi gây chu trình, ví dụ:

   `A -> B -> C -> A`

5. Không cho dependency nối hai issue thuộc các dự án khác nhau.
6. Xử lý dependency trùng lặp đúng quy tắc.
7. Hiển thị blocker rõ ràng trong giao diện.
8. Phân biệt dependency đã hoàn thành/được xác nhận với dependency chưa đủ điều kiện.
9. Dùng prepared statements và transaction khi phù hợp.
10. Thiết kế xử lý cạnh tranh dữ liệu để hai request cùng thêm dependency không thể vô tình tạo chu trình.

Nếu cần, dùng cơ chế khóa dự án hoặc phương án tuần tự hóa thao tác thêm dependency. Không triển khai giải pháp phức tạp hơn mức cần thiết.

## Test bắt buộc

- A phụ thuộc B.
- B phụ thuộc C.
- Thêm C phụ thuộc A phải bị từ chối.
- Kết quả phải chỉ rõ chuỗi vòng.
- Issue tự phụ thuộc phải bị từ chối.
- Dependency khác dự án phải bị từ chối.
- Dependency hợp lệ không tạo vòng vẫn được lưu.
- Quan hệ bị từ chối không được ghi vào database.

---

# V. GIAI ĐOẠN 3 — HOÀN THIỆN VÒNG ĐỜI ISSUE VÀ KẾT QUẢ KIỂM THỬ

Rà soát `issues`, `comments`, `test_results` và các service liên quan.

## 1. Optimistic locking

Kiểm tra cột `issues.version` và tất cả đường cập nhật issue.

Quy tắc:

- Mọi chỉnh sửa có thể xung đột phải sử dụng version nền.
- UPDATE phải kiểm tra ID, phạm vi dự án, version nền và các điều kiện quyền tương ứng.
- Version tăng sau khi cập nhật thành công.
- Nếu không cập nhật được do version đã thay đổi, phải xác định là xung đột hoặc từ chối thao tác theo đúng nguyên nhân.
- Không cập nhật version một cách thiếu kiểm soát ở đường code phụ.
- Không tự động ghi đè bản mới bằng dữ liệu cũ.

Phản hồi xung đột cần hướng người dùng tải lại dữ liệu hoặc so sánh thay đổi trước khi lưu lại.

## 2. Kết quả kiểm thử sau sửa

Rà soát quy tắc trạng thái `verified`.

Phân biệt rõ:

- Chưa có kết quả kiểm thử.
- Kết quả kiểm thử thất bại.
- Kết quả kiểm thử thành công.
- Kết quả kiểm thử không còn hợp lệ vì issue đã thay đổi sau thời điểm test.

Kết quả `passed` cũ không được tự động coi là bằng chứng xác nhận dữ liệu mới.

Hãy kiểm tra phương án lưu version issue được kiểm thử trong `test_results` hoặc một cơ chế tương đương phù hợp với schema hiện tại. Chọn giải pháp dễ hiểu và có test chứng minh.

Không tự động giữ `verified` nếu logic thực tế cho thấy lần sửa mới đã làm mất hiệu lực của kết quả xác nhận trước đó.

## 3. Bình luận và lịch sử

- Validate nội dung.
- Kiểm tra quyền trước khi tạo.
- Xác nhận insert thành công trước khi phản hồi.
- Bảo đảm bình luận chỉ liên kết với issue hợp lệ.
- Không cho thành viên dự án khác đọc hoặc sửa bình luận nếu quyền hiện tại không cho phép.

Không bắt buộc xây dựng hệ thống quản lý bình luận phức tạp ngoài phạm vi đề tài.

---

# VI. GIAI ĐOẠN 4 — HOÀN THIỆN RELEASE SERVICE

Đây là phần ưu tiên kỹ thuật cao nhất.

Rà soát `ReleaseService.php`, schema và mọi endpoint có thể thay đổi release hoặc danh sách issue thuộc release.

## 1. Vòng đời release

Hoàn thiện các trạng thái:

- `planned`: phiên bản dự kiến, còn được chỉnh sửa.
- `ready`: đã vượt qua các điều kiện phát hành được công bố.
- `released`: đã chốt và phát hành.

Thiết kế chuyển trạng thái rõ ràng:

- `planned -> ready`: chỉ khi tất cả điều kiện bắt buộc đều đạt.
- `ready -> released`: chỉ khi kiểm tra cuối cùng vẫn đạt và transaction chốt thành công.
- Release chưa đủ điều kiện không được nhảy thẳng sang `released`.
- Release đã `released` không được quay lại trạng thái có thể sửa bằng các đường endpoint thông thường.

Nếu phát hiện dự án đã có logic trạng thái khác, hãy điều chỉnh tương thích mà không phá các chức năng đang hoạt động.

## 2. Điều kiện phát hành

Kiểm tra ở phía server tối thiểu các điều kiện:

- Issue được liên kết với release thuộc đúng dự án.
- Dependency không tạo vòng và các công việc tiên quyết thỏa mãn quy tắc đã công bố.
- Không có lỗi mức chặn chưa kiểm tra lại hợp lệ.
- Kết quả kiểm thử dùng để xác nhận không bị lỗi thời.
- Các dữ liệu bắt buộc phục vụ snapshot đều hợp lệ.
- Người thao tác có vai trò được phép.

Điều kiện phải được công bố bằng mô tả dễ hiểu trên giao diện. Khi không đạt, trả về danh sách lý do cụ thể.

Không sử dụng một điều kiện hardcode ẩn mà người dùng không thể biết vì sao release chưa sẵn sàng.

Không tạo một bộ workflow tổng quát vượt quá phạm vi dự án.

## 3. Transaction và locking

Sửa lỗi cạnh tranh ở bước chốt release.

Quy trình yêu cầu:

1. Bắt đầu transaction.
2. Kiểm tra quyền truy cập và khóa bản ghi cần thiết.
3. Sử dụng thứ tự khóa thống nhất giữa các thao tác có liên quan.
4. Xác nhận release chưa ở trạng thái `released`.
5. Kiểm tra lại các điều kiện trên dữ liệu nhất quán.
6. Tạo snapshot của danh sách issue và các trường đã công bố.
7. Chuyển release sang `released`, ghi thời gian chốt.
8. Ghi audit log trong cùng transaction.
9. Commit chỉ khi tất cả bước thành công.
10. Rollback toàn bộ nếu bất kỳ bước nào thất bại.

Có thể dùng `SELECT ... FOR UPDATE` trong PostgreSQL hoặc một phương án tương đương được chứng minh đúng.

Lưu ý quan trọng:

**Khóa release đơn thuần không ngăn issue nguồn bị sửa trong lúc kiểm tra.**

Hãy thiết kế cơ chế đồng bộ với các thao tác sửa issue và dependency ảnh hưởng đến điều kiện phát hành. Có thể dùng khóa bản ghi dự án làm điểm tuần tự hóa cho các thao tác liên quan trong phạm vi lab, miễn là các service liên quan thực sự tuân thủ cùng giao thức khóa.

Không để hai luồng code dùng thứ tự khóa khác nhau một cách tùy tiện.

Kiểm tra số dòng được cập nhật ở các câu UPDATE quan trọng. Không được bỏ qua trường hợp UPDATE không tác động bản ghi nào.

Xử lý lỗi deadlock hoặc serialization nếu xuất hiện; không tự động retry các thao tác không bảo đảm an toàn khi chạy lại.

## 4. Snapshot bất biến

Khi release được chốt:

- Snapshot phải được tạo từ dữ liệu đã được kiểm tra.
- `release_items` chứa đủ trường cần để tái tạo danh sách thay đổi và ghi chú phát hành.
- Không đọc lại dữ liệu issue hiện tại khi xuất ghi chú cho release cũ.
- Các đường CRUD thông thường phải từ chối sửa hoặc xóa snapshot của release `released`.
- Không cho phép thêm/xóa issue trong release đã chốt.
- Lưu audit log về hành động chốt.
- Đảm bảo retry hoặc request trùng lặp không làm snapshot bị ghi đè.

Cân nhắc constraint hoặc trigger PostgreSQL nếu có ích, nhưng phải tránh tạo cơ chế bảo vệ trùng lặp khó bảo trì. Chọn phương án phù hợp với hệ thống hiện có và chứng minh bằng test.

## 5. Quản lý điều kiện phát hành

Đầu tiên, kiểm tra logic hiện có.

Nếu các điều kiện là tập quy tắc cố định theo đề, có thể giữ trong `ReleaseService`, nhưng cần công bố rõ ràng trên UI.

Nếu cần quản lý điều kiện như dữ liệu, hãy cân nhắc migration thêm `release_conditions` với phạm vi vừa đủ, ví dụ mã, tên, mô tả, phạm vi áp dụng và trạng thái kích hoạt.

Không thêm bảng chỉ để làm schema phức tạp hơn.

---

# VII. GIAI ĐOẠN 5 — MIGRATION VÀ TOÀN VẸN SCHEMA

Được phép thay đổi schema.

Hãy kiểm tra dữ liệu trước khi đưa ra migration. Không giả định rằng trạng thái database thực tế giống hoàn toàn seed mẫu.

## Yêu cầu

1. Tạo cơ chế migration có phiên bản, chạy theo thứ tự xác định.
2. Ghi nhận migration đã áp dụng.
3. Có thể tạo database mới rồi áp dụng migration và seed.
4. Không chạy migration lặp lại gây duplicate hoặc mất dữ liệu.
5. Giữ dữ liệu dự án, issue, dependency, release đang có.
6. Không đổi ID bản ghi đang được tham chiếu nếu không có migration dữ liệu đầy đủ.
7. Bổ sung foreign key, unique constraint và check constraint phù hợp.
8. Kiểm tra ràng buộc quan hệ phụ thuộc cùng dự án ở database, không chỉ ở PHP.
9. Kiểm tra tính hợp lệ của trạng thái release.
10. Cập nhật seed khi schema thay đổi.

Nếu cần khóa ngoại tổng hợp để đảm bảo hai issue thuộc cùng dự án, kiểm tra cách khai báo unique key và foreign key tương ứng trong PostgreSQL.

Mỗi migration phải giải thích được mục đích, ảnh hưởng dữ liệu và cách kiểm tra sau khi chạy.

Không xóa database hiện tại để áp dụng migration.

---

# VIII. GIAI ĐOẠN 6 — QUẢN LÝ DỰ ÁN, THÀNH VIÊN VÀ AUDIT

Hoàn thiện chức năng còn thiếu mà không mở rộng quá mức đề tài.

## 1. Quản lý dự án và thành viên

Bổ sung những màn hình cần thiết để:

- Xem danh sách dự án.
- Tạo hoặc sửa dự án trong phạm vi quyền.
- Xem thành viên của dự án.
- Thêm thành viên.
- Thay đổi vai trò.
- Thu hồi quyền truy cập.
- Không cho người dùng tự nâng quyền trái phép.

Đảm bảo admin và release manager không mặc định có quyền giống nhau nếu chưa có chính sách cho phép.

## 2. Audit log

Bổ sung trang tra cứu `audit_log` với thông tin tối thiểu:

- Thời gian.
- Người thực hiện.
- Dự án.
- Hành động.
- Loại đối tượng và ID đối tượng.
- Chi tiết thay đổi cần thiết.

Có lọc cơ bản theo dự án, người dùng hoặc loại thao tác nếu phù hợp với cấu trúc hiện tại.

Kiểm soát quyền xem log. Không ghi bí mật vào trường details.

## 3. Release notes

Bổ sung khả năng xuất file Markdown hoặc TXT thực sự. Có thể giữ trang in hiện tại nếu vẫn hoạt động.

Release notes phải được tái tạo từ snapshot đã chốt, không lấy dữ liệu hiện tại của issue nguồn.

---

# IX. GIAI ĐOẠN 7 — KIỂM THỬ TỰ ĐỘNG BẮT BUỘC

Phải triển khai đầy đủ năm phép thử sau. Đây không phải phần tùy chọn.

## Test 1 — Phát hiện chu trình

Thiết lập A phụ thuộc B, B phụ thuộc C.

Khi thêm C phụ thuộc A:

- Thao tác bị từ chối.
- Thông báo có chuỗi `A -> B -> C -> A`.
- Database không lưu quan hệ tạo vòng.

## Test 2 — Blocker ngăn phát hành

Tạo release có lỗi mức chặn chưa được kiểm tra lại hợp lệ.

- Không cho chuyển sang `ready`.
- Không cho phát hành.
- Trả về lý do cụ thể.

Bổ sung trường hợp chưa có kết quả kiểm thử, kết quả failed và kết quả passed đã lỗi thời.

## Test 3 — Optimistic locking

Hai client cùng đọc một issue tại cùng một version.

- Client A lưu thành công.
- Client B lưu bằng version cũ.
- Client B nhận xung đột.
- Giá trị do Client A lưu vẫn được giữ nguyên.
- Version không bị tăng sai.

Có test tuần tự cho logic version và test cạnh tranh bằng hai request/process độc lập khi môi trường cho phép.

## Test 4 — Phân quyền chéo dự án

User chỉ thuộc dự án A cố sửa issue của dự án B.

- Server từ chối thao tác.
- Không thay đổi database.
- Không tiết lộ dữ liệu không được phép.
- Có test cho cả endpoint và service/query liên quan.

## Test 5 — Snapshot bất biến

- Tạo release và thêm issue.
- Chốt release.
- Lưu lại nội dung `release_items`.
- Sửa các trường issue nguồn sau phát hành.
- Đọc lại snapshot và xuất release notes.

Snapshot và release notes của release cũ phải không thay đổi.

## Các test bổ sung cần có

- Đăng nhập đúng/sai.
- CSRF thiếu token hoặc token sai.
- Kiểm soát quyền theo vai trò.
- Dependency khác dự án.
- Dependency trùng lặp.
- Kiểm thử lại sau khi issue thay đổi.
- Tạo comment và test result với ID không hợp lệ.
- Chốt release đồng thời.
- Transaction rollback khi có lỗi giữa quá trình chốt.
- Migration và seed.
- Regression cho các luồng nghiệp vụ hiện có.

## Cấu trúc test

Ưu tiên cấu trúc rõ ràng, có thể giữ `tests/run.php` làm entry point.

Có thể sử dụng:

- Unit test cho thuật toán đồ thị và điều kiện phát hành.
- Integration test cho PostgreSQL, optimistic locking, quyền, transaction và snapshot.
- Smoke test cho đăng nhập và các trang chính.

Không bắt buộc dùng PHPUnit nếu gây ra nhiều thay đổi không cần thiết. Nếu dùng PHP thuần, phải có assertion, báo cáo pass/fail và exit code khác 0 khi bất kỳ test bắt buộc nào thất bại.

Tạo database thử nghiệm riêng. Không chạy test phá dữ liệu trên database đang được sử dụng.

Đối với race condition, dùng hai connection hoặc hai process độc lập; không giả vờ kiểm thử concurrency bằng hai lần gọi hàm tuần tự.

Không báo test pass nếu chưa thực sự chạy.

---

# X. GIAI ĐOẠN 8 — DỌN CODE COMMUNITYPASS

Dự án còn các file được báo cáo thuộc CommunityPass, có thể gồm:

- `Draft.php`
- `EventFilter.php`
- `Input.php`
- `RegistrationRepository.php`
- `RegistrationStats.php`
- `Session.php`
- `demo-db.php`
- `tests/extension.php`
- `tests/ui-demo.php`
- `data/`
- `templates/`
- `public/assets/`
- Các trang redirect cũ.

Đây là danh sách cần xác minh, không được coi tất cả đều chắc chắn là file thừa.

Thực hiện theo thứ tự:

1. Tìm tất cả tham chiếu đến từng file.
2. Xác định file nào đang được ReleaseTracker sử dụng.
3. Đưa test không liên quan ra khỏi bộ test chính.
4. Xóa file chỉ khi xác minh không có tham chiếu hợp lệ và không cần giữ cho tài liệu/bàn giao.
5. Không xóa dữ liệu người dùng hoặc file cấu hình cần thiết.
6. Giữ lại lịch sử Git; không rewrite lịch sử hoặc thực hiện reset phá hủy.
7. Chạy lại toàn bộ test sau khi dọn.
8. Cập nhật README và cây thư mục.

Sửa cấu hình DB còn mặc định `communitypass_lab_v5`, nếu xác minh đây là cấu hình cũ không phù hợp. Không hardcode mật khẩu thật trong code hoặc ghi thông tin bí mật vào Git.

Giữ `LICENSE` nếu phù hợp; kiểm tra và bổ sung thông tin nguồn sử dụng cần thiết.

---

# XI. GIAI ĐOẠN 9 — TỔ CHỨC CODE VÀ CHẤT LƯỢNG

Giữ kiến trúc phù hợp với dự án PHP thuần.

Không cần xây dựng một hệ thống nhiều lớp quá mức. Nhưng cần:

- Tách rõ logic nghiệp vụ và xử lý HTTP.
- Không lặp lại kiểm tra quyền theo cách thiếu nhất quán.
- Không để controller tự xử lý toàn bộ thuật toán đồ thị hoặc transaction release.
- Dùng exception hoặc cơ chế lỗi nhất quán.
- Dùng transaction rõ ràng.
- Có type declaration phù hợp với PHP 8.1+.
- Tránh code chết, tên biến khó hiểu và copy-paste logic.
- Không thêm dependency ngoài nếu chưa có lý do thuyết phục.
- Không làm mất tính tương thích với PHP built-in server.

Nếu đổi cấu trúc file, cập nhật toàn bộ nơi tham chiếu và hướng dẫn khởi chạy.

---

# XII. QUY TRÌNH TRIỂN KHAI BẮT BUỘC

Thực hiện theo từng nhóm commit logic, không sửa tất cả trong một đợt không kiểm soát.

## Bước 1

Kiểm tra Git, code và test baseline. Không ghi đè thay đổi chưa commit.

## Bước 2

Sửa security, validation và cấu hình môi trường.

Chạy test liên quan.

## Bước 3

Sửa dependency và optimistic locking.

Chạy unit test, integration test liên quan.

## Bước 4

Sửa ReleaseService, transaction, snapshot và trạng thái release.

Chạy test release, concurrency và snapshot.

## Bước 5

Tạo migration, bổ sung constraint và giữ tương thích dữ liệu.

Kiểm tra DB mới và DB đã có dữ liệu bằng môi trường thử nghiệm riêng.

## Bước 6

Hoàn thiện quản lý dự án/thành viên, audit UI và release notes.

Chạy regression.

## Bước 7

Dọn code CommunityPass, kiểm tra LICENSE và README.

Chạy toàn bộ test lần cuối.

Không cần tạo commit Git tự động nếu chưa được yêu cầu. Nhưng phải giữ thay đổi thành từng nhóm dễ review và thông báo cách tổ chức chúng.

---

# XIII. DEFINITION OF DONE

Dự án chỉ được coi là hoàn thành khi đáp ứng tất cả điều kiện sau:

1. Ứng dụng khởi chạy được bằng PHP và PostgreSQL trong môi trường mục tiêu.
2. Seed vẫn có dữ liệu phù hợp với yêu cầu.
3. Không có regression chưa xử lý.
4. Năm phép thử bắt buộc tự động chạy và pass.
5. Đã kiểm tra hành vi optimistic locking.
6. Đã kiểm tra transaction và race condition khi chốt release.
7. Đã kiểm tra quyền chéo dự án ở server.
8. Snapshot và release notes cũ bất biến sau khi issue nguồn thay đổi.
9. Migration có thể chạy theo trình tự mà không xóa dữ liệu hiện có.
10. Điều kiện phát hành được công bố và thực thi ở phía server.
11. Code CommunityPass không sử dụng đã được loại khỏi sản phẩm và luồng test chính.
12. README hướng dẫn cài đặt, cấu hình, seed, test và chạy ứng dụng.
13. Có mô tả schema, kiến trúc và các quyết định kỹ thuật quan trọng.
14. Có báo cáo rõ ràng về test nào đã chạy thực tế, test nào chưa chạy được và nguyên nhân.

---

# XIV. CÁCH BÁO CÁO KẾT QUẢ

Sau mỗi nhóm thay đổi, hãy báo cáo ngắn gọn:

- Những file đã thay đổi.
- Vấn đề được giải quyết.
- Quyết định kỹ thuật quan trọng.
- Migration hoặc thay đổi schema (nếu có).
- Test đã chạy và kết quả thực tế.
- Test chưa chạy, nguyên nhân.
- Rủi ro hoặc việc còn tồn đọng.

Khi hoàn tất, hãy cung cấp:

1. Tóm tắt các thay đổi.
2. Danh sách file chính đã sửa, thêm và xóa.
3. Các migration và ảnh hưởng dữ liệu.
4. Kết quả từng test bắt buộc.
5. Kết quả test hồi quy.
6. Hướng dẫn chạy ứng dụng và kiểm thử.
7. Hạn chế còn tồn tại.
8. Kịch bản demo hai tình huống nghiệp vụ.
9. Các điểm tôi cần nắm để giải thích khi vấn đáp.

**Tuyệt đối không tuyên bố hoàn thành hoặc test pass nếu chưa có bằng chứng chạy thực tế.**

Nếu gặp lỗi môi trường, hãy nói rõ lỗi môi trường và tiếp tục hoàn thành các phần có thể thực hiện. Không dừng lại chỉ để hỏi những câu có thể giải quyết bằng cách kiểm tra repository.

Bắt đầu bằng việc khảo sát workspace, sau đó thực hiện lần lượt toàn bộ các giai đoạn trên. Giữ phạm vi sát đề tài, ưu tiên tính đúng đắn, bảo mật, toàn vẹn dữ liệu và khả năng kiểm thử hơn số lượng tính năng hoặc độ phức tạp không cần thiết.