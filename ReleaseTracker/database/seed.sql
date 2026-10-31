-- Dữ liệu demo. Mật khẩu cho các tài khoản demo: demo1234 (chỉ dùng cục bộ).
INSERT INTO users(username,full_name,password_hash) VALUES
('dev_a','Nguyễn An','$2y$12$93whwDc3gqE985twbzxinOMz2aEWkQkerQcTtuU5.bcyIXZ0EDdSi'),('tester_a','Trần Bình','$2y$12$93whwDc3gqE985twbzxinOMz2aEWkQkerQcTtuU5.bcyIXZ0EDdSi'),('release_a','Lê Chi','$2y$12$93whwDc3gqE985twbzxinOMz2aEWkQkerQcTtuU5.bcyIXZ0EDdSi'),('dev_b','Phạm Dũng','$2y$12$93whwDc3gqE985twbzxinOMz2aEWkQkerQcTtuU5.bcyIXZ0EDdSi'),('admin_a','Quản trị viên','$2y$12$93whwDc3gqE985twbzxinOMz2aEWkQkerQcTtuU5.bcyIXZ0EDdSi')
ON CONFLICT(username) DO NOTHING;
INSERT INTO projects(name,description) VALUES
('ReleaseTracker Core','Theo dõi lỗi và phát hành hệ thống quản lý công việc.'),
('Mobile Client','Ứng dụng khách trên thiết bị di động.') ON CONFLICT DO NOTHING;
INSERT INTO project_members(project_id,user_id,role)
SELECT p.id,u.id,
 CASE u.username
   WHEN 'tester_a' THEN 'tester'
   WHEN 'release_a' THEN 'release_manager'
   WHEN 'admin_a' THEN 'admin'
   ELSE 'developer'
 END
FROM projects p CROSS JOIN users u
WHERE (p.name='ReleaseTracker Core' AND u.username IN ('dev_a','tester_a','release_a','admin_a'))
   OR (p.name='Mobile Client' AND u.username IN ('dev_b','admin_a'))
ON CONFLICT DO NOTHING;

INSERT INTO issues(project_id,title,description,reproduction_steps,issue_type,priority,status,reporter_id,assignee_id)
SELECT p.id, x.title, x.description, x.steps, x.kind, x.priority, x.status, u1.id, u2.id
FROM (VALUES
('Lỗi đăng nhập khi token hết hạn','Phiên đăng nhập không được làm mới.','1. Đăng nhập 2. Chờ token hết hạn 3. Tải trang','bug','blocker','open','dev_a','dev_a'),
('API trả mã lỗi không nhất quán','Chuẩn hóa phản hồi lỗi API.','Gọi endpoint với dữ liệu sai','bug','critical','in_progress','dev_a','dev_a'),
('Bổ sung lọc danh sách công việc','Cho phép lọc theo trạng thái.','Mở danh sách và chọn bộ lọc','feature','medium','open','dev_a','dev_a'),
('Nút lưu không hiển thị trạng thái','Người dùng không biết lưu thành công.','Sửa mô tả rồi lưu','bug','high','resolved','tester_a','dev_a'),
('Thiếu kiểm tra quyền thành viên','Thành viên dự án khác có thể xem dữ liệu.','Đăng nhập bằng tài khoản dự án khác','bug','blocker','open','tester_a','dev_a'),
('Bổ sung ghi chú phát hành','Xuất ghi chú phiên bản dạng Markdown.','Mở trang phiên bản','feature','medium','in_progress','dev_a','dev_a'),
('Lỗi phân trang khi trang rỗng','Trang cuối có thể trả lỗi.','Tìm kiếm không có kết quả','bug','high','open','tester_a','dev_a'),
('Thêm kiểm thử hồi quy','Bổ sung kiểm thử cho luồng đăng nhập.','Chạy bộ test hồi quy','task','medium','open','dev_a','dev_a'),
('Thông báo lỗi biểu mẫu chưa rõ','Nêu trường nào không hợp lệ.','Gửi biểu mẫu thiếu dữ liệu','bug','low','resolved','tester_a','dev_a'),
('Tối ưu truy vấn bảng công việc','Giảm số truy vấn trong dashboard.','Mở dashboard với nhiều bản ghi','task','high','in_progress','dev_a','dev_a'),
('Đồng bộ trạng thái kiểm thử','Hiển thị kết quả kiểm thử mới nhất.','Chạy kiểm thử sau khi sửa','bug','critical','retest','tester_a','dev_a'),
('Liên kết commit vào công việc','Lưu URL commit hoặc pull request.','Cập nhật trường liên kết','feature','low','open','dev_a','dev_a'),
('Kiểm tra dữ liệu đầu vào','Từ chối chuỗi chỉ có khoảng trắng.','Gửi dữ liệu khoảng trắng','bug','high','open','tester_a','dev_a'),
('Cải thiện trang chi tiết lỗi','Hiển thị người phụ trách và lịch sử.','Mở chi tiết lỗi','feature','medium','open','dev_a','dev_a'),
('Không giữ trạng thái bộ lọc','Bộ lọc bị mất sau khi quay lại.','Lọc rồi mở chi tiết và quay lại','bug','medium','resolved','tester_a','dev_a'),
('Bổ sung log thao tác quan trọng','Lưu lịch sử thay đổi trạng thái.','Sửa trạng thái công việc','task','medium','open','dev_a','dev_a'),
('Lỗi xác thực quyền phát hành','Vai trò developer có thể chốt bản phát hành.','Đăng nhập developer và chốt','bug','blocker','open','tester_a','dev_a'),
('Cải thiện giao diện màn hình nhỏ','Bảng bị tràn trên thiết bị di động.','Mở trang ở chiều rộng nhỏ','task','low','open','dev_a','dev_a'),
('Kiểm tra liên kết phụ thuộc','Ngăn tạo quan hệ phụ thuộc vòng.','Tạo chuỗi A-B-C rồi thêm C-A','bug','critical','in_progress','dev_a','dev_a'),
('Thêm mô tả phiên bản','Lưu nội dung tổng quan phiên bản.','Tạo phiên bản mới','feature','low','open','dev_a','dev_a')
) AS x(title,description,steps,kind,priority,status,reporter,assignee)
JOIN projects p ON p.name='ReleaseTracker Core'
JOIN users u1 ON u1.username=x.reporter JOIN users u2 ON u2.username=x.assignee
ON CONFLICT DO NOTHING;
INSERT INTO issues(project_id,title,description,reproduction_steps,issue_type,priority,status,reporter_id,assignee_id)
SELECT p.id, 'Mobile: '||n.title, n.description, n.steps, n.kind, n.priority, n.status, u.id, u.id
FROM projects p CROSS JOIN users u CROSS JOIN (VALUES
('Ứng dụng khởi động chậm','Thời gian khởi động vượt mục tiêu.','Mở ứng dụng sau khi cài','bug','high','open'),
('Lỗi bố cục màn hình đăng nhập','Các trường nhập bị lệch.','Mở màn hình đăng nhập','bug','medium','in_progress'),
('Bổ sung chế độ ngoại tuyến','Cho phép xem nội dung đã lưu.','Tắt mạng và mở ứng dụng','feature','medium','open'),
('Thông báo đẩy bị lặp','Một sự kiện gửi nhiều thông báo.','Đăng nhập nhiều thiết bị','bug','critical','open'),
('Thêm kiểm thử trên Android','Bổ sung ca kiểm thử thiết bị.','Chạy bộ test Android','task','low','open'),
('Lỗi đồng bộ phiên đăng nhập','Phiên không được đồng bộ giữa màn hình.','Đăng xuất rồi quay lại','bug','blocker','retest'),
('Cải thiện tìm kiếm di động','Kết quả không cập nhật tức thì.','Nhập từ khóa liên tục','bug','medium','open'),
('Thêm trang cài đặt','Cho phép điều chỉnh tùy chọn.','Mở cài đặt','feature','low','open'),
('Sửa xử lý mạng yếu','Yêu cầu bị gửi lại quá nhiều.','Giảm băng thông mạng','bug','high','resolved'),
('Tối ưu ảnh đại diện','Ảnh lớn làm chậm danh sách.','Mở danh sách thành viên','task','low','open'),
('Kiểm tra phiên bản API','Client cũ gọi API không hỗ trợ.','Chạy client phiên bản cũ','bug','critical','open'),
('Bổ sung hướng dẫn cập nhật','Hiển thị thông tin bản mới.','Mở trang thông tin','feature','low','open'),
('Lỗi xoay màn hình','Nội dung mất khi xoay.','Xoay thiết bị khi đang nhập','bug','medium','open'),
('Thêm log crash','Lưu mã lỗi không chứa dữ liệu nhạy cảm.','Tạo lỗi giả lập','task','medium','open'),
('Kiểm tra quyền thông báo','Ứng dụng không xử lý khi bị từ chối.','Từ chối quyền thông báo','bug','high','open'),
('Sửa bộ nhớ cache','Nội dung cũ còn hiển thị.','Cập nhật dữ liệu rồi tải lại','bug','medium','open'),
('Cải thiện khả năng truy cập','Thiếu nhãn cho trình đọc màn hình.','Dùng trình đọc màn hình','task','low','open'),
('Lỗi khôi phục mật khẩu','Liên kết hết hạn không có thông báo.','Mở liên kết đã hết hạn','bug','high','open'),
('Tăng độ phủ kiểm thử','Bổ sung test cho xử lý lỗi.','Chạy báo cáo coverage','task','medium','open'),
('Bổ sung phiên bản beta','Hiển thị nhãn beta ở cài đặt.','Cài bản beta','feature','low','open')
) AS n(title,description,steps,kind,priority,status)
WHERE p.name='Mobile Client' AND u.username='dev_b'
ON CONFLICT DO NOTHING;
-- 15 quan hệ phụ thuộc, không tạo vòng trong dữ liệu mẫu.
INSERT INTO dependencies(issue_id,depends_on_id,issue_project_id,depends_on_project_id)
SELECT a.id,b.id,a.project_id,b.project_id FROM issues a JOIN issues b ON a.project_id=b.project_id
WHERE a.project_id=(SELECT id FROM projects WHERE name='ReleaseTracker Core') AND b.project_id=a.project_id
AND ((a.title LIKE 'Lỗi đăng nhập%' AND b.title LIKE 'API trả mã%')
OR (a.title LIKE 'Bổ sung lọc%' AND b.title LIKE 'Tối ưu truy vấn%')
OR (a.title LIKE 'Thông báo lỗi biểu mẫu%' AND b.title LIKE 'Kiểm tra dữ liệu%')
OR (a.title LIKE 'Thêm kiểm thử hồi quy%' AND b.title LIKE 'API trả mã%')
OR (a.title LIKE 'Đồng bộ trạng thái%' AND b.title LIKE 'Liên kết commit%')
OR (a.title LIKE 'Cải thiện trang chi tiết%' AND b.title LIKE 'Bổ sung log thao tác%')
OR (a.title LIKE 'Thêm mô tả phiên bản%' AND b.title LIKE 'Bổ sung ghi chú%')
OR (a.title LIKE 'Cải thiện giao diện%' AND b.title LIKE 'Lỗi phân trang%')
OR (a.title LIKE 'Kiểm tra liên kết phụ thuộc%' AND b.title LIKE 'Bổ sung log thao tác%')
OR (a.title LIKE 'Bổ sung ghi chú%' AND b.title LIKE 'Kiểm tra liên kết phụ thuộc%')
OR (a.title LIKE 'Lỗi xác thực quyền%' AND b.title LIKE 'Kiểm tra dữ liệu%')
OR (a.title LIKE 'Bổ sung log thao tác%' AND b.title LIKE 'API trả mã%')
OR (a.title LIKE 'Thêm kiểm thử hồi quy%' AND b.title LIKE 'Kiểm tra dữ liệu%')
OR (a.title LIKE 'Thông báo lỗi biểu mẫu%' AND b.title LIKE 'Lỗi phân trang%')
OR (a.title LIKE 'Cải thiện trang chi tiết%' AND b.title LIKE 'Lỗi phân trang%'))
ON CONFLICT DO NOTHING;
INSERT INTO releases(project_id,version_name,description,created_by)
SELECT p.id,v.version,v.description,u.id FROM projects p CROSS JOIN users u CROSS JOIN (VALUES
('1.0.0','Bản phát hành nền tảng đầu tiên'),('1.1.0','Cải thiện quản lý lỗi và kiểm thử'),('2.0.0','Bản phát hành lớn tiếp theo')
) v(version,description) WHERE p.name='ReleaseTracker Core' AND u.username='release_a'
ON CONFLICT(project_id,version_name) DO NOTHING;
