# RELEASETRACKER — SỬA P1-2: TOCTOU PHÂN QUYỀN VÀ KIỂM TRA QUYỀN TRONG TRANSACTION

Bạn là Senior PHP Engineer, PostgreSQL Engineer và Security Engineer. Hãy trực tiếp kiểm tra, sửa chữa và kiểm thử lỗi P1-2 được xác định trong báo cáo audit ReleaseTracker.

Đây là nhiệm vụ triển khai sửa code, không chỉ phân tích hoặc đưa ra kế hoạch.

## I. BỐI CẢNH

ReleaseTracker sử dụng:

- PHP 8.1+ thuần.
- PostgreSQL.
- Phân quyền theo thành viên và vai trò trong từng dự án.
- `projectLock()` để khóa bản ghi dự án bằng cơ chế PostgreSQL phù hợp.
- Các service như `IssueService`, `DependencyService`, `ReleaseService`.
- Các controller trong `public/`.

Theo báo cáo audit, hiện có các luồng thực hiện kiểm tra quyền trước khi mở transaction hoặc khóa dự án. Điều này tạo ra rủi ro TOCTOU (Time-of-check to Time-of-use).

Ví dụ:

1. Request A kiểm tra người dùng có vai trò `admin` hoặc `release_manager`.
2. Request A chưa khóa dự án hoặc chưa kiểm tra lại membership bên trong transaction.
3. Request B thu hồi thành viên hoặc hạ vai trò của người dùng.
4. Request A tiếp tục thực hiện thao tác bằng kết quả kiểm tra quyền cũ.

Rủi ro tương tự có thể xảy ra khi một người dùng bị thu hồi membership hoặc bị thay đổi vai trò đúng thời điểm request đang xử lý.

Báo cáo audit đã nêu các vị trí cần xem xét:

- `public/projects.php`
- `public/releases.php`
- `src/IssueService.php`
- `src/Database.php`
- Các route/service quản lý thành viên dự án.
- Các thao tác dependency, comment, test result, release và audit.

Hãy xác minh lại các vị trí này theo code thực tế. Không giả định cấu trúc file hoặc số dòng đã giữ nguyên.

## II. NGUYÊN TẮC

1. Kiểm tra code thực tế trước khi sửa.
2. Giữ nguyên PHP thuần và PostgreSQL.
3. Không viết lại toàn bộ dự án.
4. Không làm yếu các kiểm tra quyền hiện có.
5. Không dựa vào việc ẩn nút giao diện để bảo vệ endpoint.
6. Không dựa duy nhất vào kết quả kiểm tra quyền được thực hiện trước transaction.
7. Không tự ý thay đổi dữ liệu hoặc chạy migration trên database chính.
8. Không tự sửa các nhóm vấn đề khác ngoài phạm vi P1-2, trừ khi cần thiết để bảo đảm an toàn của bản sửa này.
9. Bảo toàn các kiểm thử P1-1 vừa được bổ sung; không xóa hoặc làm yếu chúng.
10. Chạy test ghi dữ liệu trên schema/database thử nghiệm riêng.
11. Không tuyên bố test pass khi chưa chạy thực tế.
12. Kiểm tra Git status nếu có Git; không reset, checkout hoặc ghi đè thay đổi hiện có. Nếu không có Git, giữ thay đổi nhỏ, dễ review.

Trước khi sửa, hãy xác định tất cả các đường ghi dữ liệu có liên quan đến membership và quyền dự án. Sau đó trực tiếp triển khai bản sửa và kiểm thử.

## III. THIẾT KẾ CƠ CHẾ AUTHORIZATION AN TOÀN

### 1. Xây dựng giao thức khóa thống nhất

Đọc kỹ `Database::projectLock()` và mọi nơi gọi hàm này.

Thiết lập một giao thức rõ ràng cho các thao tác có ảnh hưởng đến quyền trong dự án:

1. Bắt đầu transaction.
2. Khóa bản ghi dự án theo giao thức của ứng dụng.
3. Đọc lại membership và vai trò hiện tại từ database.
4. Kiểm tra người thực hiện còn quyền thực hiện thao tác hay không.
5. Khóa hoặc cập nhật dữ liệu mục tiêu theo thứ tự khóa thống nhất.
6. Thực hiện thay đổi dữ liệu.
7. Commit transaction.

Nếu người dùng đã bị xóa khỏi dự án hoặc bị hạ quyền trước khi request lấy được khóa dự án, request phải bị từ chối theo vai trò hiện tại.

Không sử dụng role do client gửi lên. Không coi role được đọc trước transaction là nguồn quyền có hiệu lực vĩnh viễn.

### 2. Khóa dự án phải được tuân thủ bởi cả luồng thay đổi quyền

Chỉ đọc lại quyền trong một transaction là chưa đủ nếu các thao tác quản lý thành viên có thể sửa `project_members` mà không tuân theo cùng giao thức khóa.

Vì vậy, hãy kiểm tra và điều chỉnh các thao tác:

- Thêm thành viên.
- Thay đổi vai trò thành viên.
- Thu hồi thành viên.
- Các thao tác quản lý dự án có ảnh hưởng đến quyền.
- Các thao tác khác làm thay đổi dữ liệu membership.

Các thao tác trên cần sử dụng transaction và khóa dự án theo thứ tự thống nhất trước khi thay đổi membership.

Mục tiêu là tạo một điểm tuần tự hóa rõ ràng cho các thao tác thay đổi quyền và các thao tác nghiệp vụ cần sử dụng những quyền đó.

Không để một transaction giữ khóa dự án trong khi transaction khác thay đổi role mà không tuân theo cùng giao thức.

### 3. Kiểm tra quyền trong service

Kiểm tra xem các service có tự bảo vệ ranh giới phân quyền hay chỉ dựa vào controller gọi chúng.

Đặc biệt xem xét:

- `IssueService::create()`
- `IssueService::update()`
- Tạo comment.
- Ghi kết quả kiểm thử.
- Thêm hoặc xóa dependency.
- Thêm hoặc gỡ issue khỏi release.
- Chuyển release sang `ready`.
- Chốt release.
- Quản lý membership.
- Truy cập audit log nếu có thao tác đọc dữ liệu nhạy cảm.

Ưu tiên các thao tác ghi dữ liệu và các thao tác nhạy cảm đối với quyền dự án.

Các controller có thể tiếp tục kiểm tra quyền sớm để trả phản hồi nhanh và rõ ràng, nhưng các đường service/database có thể được gọi độc lập vẫn phải có bảo vệ phù hợp.

Nếu bổ sung kiểm tra quyền trong service, hãy giữ tương thích với chữ ký hàm, các controller hiện tại và các test đang sử dụng service.

Không tạo logic phân quyền trùng lặp theo nhiều cách không nhất quán. Hãy sử dụng helper/service kiểm tra quyền hiện có nếu phù hợp, hoặc bổ sung một phương thức dùng chung.

Không cho rằng `admin`, `release_manager`, `developer` và `tester` có quyền tương đương. Phải giữ nguyên chính sách vai trò hiện tại, trừ khi có lỗi phân quyền được xác nhận cần sửa.

### 4. Phản hồi khi bị từ chối

Khi quyền không còn hợp lệ:

- Request phải bị từ chối trước khi ghi thay đổi nghiệp vụ.
- Database không bị thay đổi một phần.
- Không tạo audit event ghi rằng thao tác thành công.
- Không tiết lộ dữ liệu của dự án khác.
- Trả HTTP 403 hoặc phản hồi phù hợp với quy ước hiện tại.
- Không làm lộ SQL, token hoặc thông tin nhạy cảm.

Nếu request bị từ chối do membership hoặc role đã thay đổi đồng thời, thông báo nên cho biết người dùng cần tải lại trang hoặc đăng nhập lại nếu cần.

## IV. KIỂM TRA CÁC TRƯỜNG HỢP CỤ THỂ

### Test A — Role bị hạ trước khi request ghi dữ liệu lấy được khóa dự án

1. Người dùng có quyền `admin` hoặc `release_manager`.
2. Request A bắt đầu nhưng chưa lấy được khóa dự án.
3. Request B hạ vai trò người dùng.
4. Request A tiếp tục.

Kỳ vọng:

- Request A kiểm tra lại role sau khi có khóa.
- Request A bị từ chối nếu role hiện tại không cho phép thao tác.
- Không có thay đổi nghiệp vụ hoặc audit success giả.

### Test B — Membership bị thu hồi

1. Người dùng có membership trong dự án A.
2. Request A bắt đầu trước nhưng bị chặn trước khi lấy khóa.
3. Request B xóa membership.
4. Request A tiếp tục.

Kỳ vọng:

- Nếu membership đã bị thu hồi trước điểm tuần tự hóa của thao tác, request A không thể tiếp tục bằng quyền cũ.
- Không ghi issue, comment, test result hoặc dữ liệu release cho người dùng đã mất quyền.

### Test C — Service bị gọi trực tiếp

Kiểm tra `IssueService::create()` hoặc phương thức tương đương khi được gọi trực tiếp mà không đi qua controller.

Kỳ vọng:

- Người dùng không có membership hợp lệ không thể tạo issue trong dự án.
- User ID, project ID hoặc role do client kiểm soát không thể dùng để vượt quyền.
- Không thể dựa vào việc controller đã kiểm tra quyền để bỏ qua mọi bảo vệ ở service.

Nếu service hiện không nhận actor ID một cách tường minh, đánh giá cách truyền danh tính người thao tác ít thay đổi nhất mà vẫn bảo đảm rõ ràng và nhất quán.

### Test D — Không phá tính đúng đắn của thao tác hợp lệ

- Developer có quyền sửa issue được phép sửa.
- Tester có quyền ghi kết quả kiểm thử theo chính sách hiện tại.
- Release manager có quyền chuyển release theo chính sách.
- Admin có thể thực hiện thao tác quản trị được phép.

Kỳ vọng: các thao tác hợp lệ vẫn hoạt động và không bị từ chối nhầm sau khi bổ sung khóa/quyền trong transaction.

### Test E — Kiểm tra chéo dự án

- User thuộc dự án A cố truy cập hoặc sửa issue của dự án B.
- User thuộc dự án A cố thêm issue dự án B vào release dự án A.
- User thuộc dự án A cố đọc audit log của dự án B.
- Thử gọi trực tiếp service nếu kiến trúc cho phép.

Kỳ vọng:

- Tất cả thao tác trái quyền bị chặn ở server.
- Không tạo dữ liệu sai liên kết dự án.
- Database giữ nguyên sau các request bị từ chối.

### Test F — Cạnh tranh trong quản lý membership

Kiểm tra hai request tương tác với cùng membership hoặc cùng dự án:

- Một request sửa dữ liệu nghiệp vụ.
- Một request thu hồi/hạ quyền.
- Cả hai bắt đầu gần nhau.

Kỳ vọng: kết quả phải nhất quán với thứ tự tuần tự hóa thực tế mà giao thức khóa đã xác định. Không được để request dùng quyền đã bị thay đổi trước điểm tuần tự hóa mà vẫn thành công.

## V. KIỂM THỬ TỰ ĐỘNG

Bổ sung test vào cấu trúc hiện có; không tạo framework mới nếu không cần thiết.

Ưu tiên:

- Unit test cho helper hoặc service kiểm tra quyền nếu phù hợp.
- Integration test sử dụng PostgreSQL cho transaction và khóa.
- Hai process hoặc hai connection độc lập cho các tình huống concurrency.
- HTTP integration test cho phản hồi 403 và xác nhận DB không đổi.

Không mô phỏng concurrency bằng hai lời gọi tuần tự rồi kết luận đã kiểm tra race condition.

Kiểm tra rằng test sử dụng schema thử nghiệm riêng. Không chạy seed/migration hoặc thao tác DELETE/UPDATE lên database chính.

Chạy và báo cáo các nhóm test:

1. Test kiểm tra quyền hiện có.
2. Test A–F được bổ sung.
3. Năm phép thử bắt buộc của đề tài.
4. Các test P1-1 vừa hoàn thành.
5. Toàn bộ unit/integration test của dự án.

Nếu không chạy được test do môi trường, ghi rõ test nào chưa chạy, lý do và cách chạy an toàn sau này.

## VI. KIỂM SOÁT PHẠM VI

Trong lần này:

- Tập trung sửa P1-2 về TOCTOU và service-level authorization.
- Không xử lý cleanup các bản ghi legacy `issue_version = 0`.
- Không chạy seed hoặc migration trên database chính.
- Không thay đổi logic snapshot nếu không liên quan trực tiếp đến quyền.
- Không thêm rate limit hoặc refactor UI không liên quan.
- Không tự ý thay đổi chính sách release về trạng thái `closed`.
- Không xóa test cũ.
- Không làm hỏng kết quả kiểm thử P1-1.

Nếu phát hiện lỗi khác không nằm trong phạm vi, ghi nhận thành backlog thay vì mở rộng thay đổi một cách tùy tiện.

## VII. TIÊU CHÍ HOÀN THÀNH

Chỉ coi P1-2 hoàn thành khi:

1. Các thao tác nhạy cảm kiểm tra membership và role hiện tại trước khi ghi dữ liệu.
2. Kiểm tra quyền diễn ra bên trong transaction theo giao thức khóa phù hợp.
3. Các thao tác thay đổi membership tuân thủ cùng giao thức khóa.
4. Các đường service không dựa mù quáng vào việc controller đã kiểm tra quyền.
5. Test role bị hạ và membership bị thu hồi trong lúc có request cạnh tranh đều có kết quả đúng.
6. Không phá các thao tác hợp lệ.
7. Test chéo dự án và regression pass.
8. Test P1-1 tiếp tục pass.
9. Database chính không bị thay đổi bởi kiểm thử.
10. Có bằng chứng về các test đã chạy thực tế.

## VIII. BÁO CÁO CUỐI

Sau khi sửa, báo cáo:

- Nguyên nhân TOCTOU tìm thấy trong code thực tế.
- Các file và phương thức đã thay đổi.
- Giao thức khóa và thứ tự khóa được áp dụng.
- Những service đã bổ sung kiểm tra quyền.
- Các trường hợp A–F đã test.
- Test đã chạy và kết quả.
- Các test chưa chạy.
- Các rủi ro còn tồn tại.
- Các vấn đề P1 khác chưa xử lý.

Bắt đầu bằng việc đọc code hiện tại, sau đó trực tiếp sửa và kiểm thử trong phạm vi này. Không dừng lại chỉ ở kế hoạch. Không tuyên bố hoàn tất nếu chưa có bằng chứng thực tế.