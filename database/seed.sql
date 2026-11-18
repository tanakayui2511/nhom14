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

-- ===================================================================
-- Bổ sung v1.1 (chạy lại nhiều lần không trùng: ON CONFLICT / NOT EXISTS).
-- ===================================================================

-- Ba công việc đã hoàn tất thuộc bản 0.9.0 đã phát hành (demo phép thử 5).
INSERT INTO issues(project_id,title,description,reproduction_steps,issue_type,priority,status,reporter_id,assignee_id,commit_url)
SELECT p.id, x.title, x.description, x.steps, x.kind, x.priority, 'closed', u1.id, u2.id, x.commit_url
FROM (VALUES
('Phát hành 0.9: trang đăng nhập cơ bản','Màn hình đăng nhập kiểm tra mật khẩu bằng password_verify.','1. Mở /login.php 2. Nhập tài khoản đúng 3. Vào được trang tổng quan','feature','high','dev_a','dev_a','https://github.com/example/releasetracker/pull/1'),
('Phát hành 0.9: mật khẩu lưu dạng rõ','Mật khẩu phải được băm bằng password_hash.','Bước tái hiện: tạo tài khoản rồi xem bảng users. Kết quả mong đợi: chuỗi băm. Kết quả thực tế: mật khẩu gốc.','bug','blocker','tester_a','dev_a','https://github.com/example/releasetracker/commit/a1b2c3d'),
('Phát hành 0.9: thiết lập CSDL và migration','Tạo schema ban đầu và công cụ migrate.','Chạy bash lab.sh migrate trên CSDL trống','task','medium','dev_a','dev_a','https://github.com/example/releasetracker/pull/2')
) AS x(title,description,steps,kind,priority,reporter,assignee,commit_url)
JOIN projects p ON p.name='ReleaseTracker Core'
JOIN users u1 ON u1.username=x.reporter JOIN users u2 ON u2.username=x.assignee
ON CONFLICT DO NOTHING;

-- Link commit/PR mẫu cho một số công việc đang làm.
UPDATE issues SET commit_url=x.url
FROM (VALUES
('API trả mã lỗi không nhất quán','https://github.com/example/releasetracker/pull/14'),
('Liên kết commit vào công việc','https://github.com/example/releasetracker/commit/9f8e7d6'),
('Mobile: Thông báo đẩy bị lặp','https://github.com/example/mobile-client/pull/3')
) AS x(title,url)
WHERE issues.title=x.title AND issues.commit_url='';

-- Bình luận mẫu.
INSERT INTO comments(issue_id,user_id,body)
SELECT i.id,u.id,x.body
FROM (VALUES
('Lỗi đăng nhập khi token hết hạn','tester_a','Tái hiện được trên Chrome và Firefox sau 30 phút không thao tác.'),
('Lỗi đăng nhập khi token hết hạn','dev_a','Đang xử lý: sẽ làm mới token trước khi hết hạn 1 phút.'),
('API trả mã lỗi không nhất quán','tester_a','Endpoint /issues trả 200 kèm thông báo lỗi; mong đợi 422.'),
('Kiểm tra liên kết phụ thuộc','release_a','Cần xong trước bản 1.1.0 vì ảnh hưởng điều kiện phát hành.'),
('Phát hành 0.9: mật khẩu lưu dạng rõ','tester_a','Đã kiểm tra lại: cột password_hash dùng bcrypt.'),
('Mobile: Thông báo đẩy bị lặp','dev_b','Nguyên nhân: đăng ký token hai lần khi khởi động lại ứng dụng.')
) AS x(title,username,body)
JOIN issues i ON i.title=x.title
JOIN users u ON u.username=x.username
WHERE NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id=i.id AND c.body=x.body);

-- Kết quả kiểm thử mẫu (passed / failed / blocked) kèm bản ghi audit làm bằng chứng nguồn gốc.
-- Kết quả failed/blocked tương ứng công việc đã được trả về in_progress.
WITH src(title,username,result,notes) AS (VALUES
('Phát hành 0.9: trang đăng nhập cơ bản','tester_a','passed','Seed: đăng nhập đúng/sai đều xử lý đúng.'),
('Phát hành 0.9: mật khẩu lưu dạng rõ','tester_a','passed','Seed: mật khẩu đã được băm.'),
('Phát hành 0.9: thiết lập CSDL và migration','tester_a','passed','Seed: migrate chạy trên CSDL trống.'),
('API trả mã lỗi không nhất quán','tester_a','failed','Seed: /issues vẫn trả 200 khi dữ liệu sai.'),
('Tối ưu truy vấn bảng công việc','tester_a','blocked','Seed: chưa có dữ liệu lớn để đo hiệu năng.')
), ins AS (
  INSERT INTO test_results(issue_id,project_id,tester_id,result,notes,issue_version)
  SELECT i.id,i.project_id,u.id,s.result,s.notes,i.version
  FROM src s
  JOIN issues i ON i.title=s.title
  JOIN users u ON u.username=s.username
  WHERE NOT EXISTS (SELECT 1 FROM test_results t WHERE t.issue_id=i.id AND t.notes=s.notes)
  RETURNING id,project_id,tester_id,result,issue_version,issue_id
)
INSERT INTO audit_log(project_id,user_id,action,entity_type,entity_id,details)
SELECT ins.project_id,ins.tester_id,'test_result_recorded','test_result',ins.id,
       jsonb_build_object('issue_id',ins.issue_id,'result',ins.result,'issue_version',ins.issue_version,'tester_role','tester')
FROM ins;

-- Phiên bản cho Mobile Client và phiên bản 0.9.0 (sẽ được chốt) cho Core.
INSERT INTO releases(project_id,version_name,description,created_by)
SELECT p.id,v.version,v.description,u.id
FROM (VALUES
('Mobile Client','m-1.0.0','Bản phát hành đầu tiên của ứng dụng di động','admin_a'),
('ReleaseTracker Core','0.9.0','Bản thử nghiệm nội bộ đã phát hành','release_a')
) v(project,version,description,username)
JOIN projects p ON p.name=v.project
JOIN users u ON u.username=v.username
ON CONFLICT(project_id,version_name) DO NOTHING;

-- Gắn công việc vào các phiên bản còn planned (snapshot tạm, được ghi lại khi chốt).
WITH src(project,version,title) AS (VALUES
('ReleaseTracker Core','1.0.0','Lỗi đăng nhập khi token hết hạn'),
('ReleaseTracker Core','1.0.0','Bổ sung ghi chú phát hành'),
('ReleaseTracker Core','1.0.0','Thêm mô tả phiên bản'),
('Mobile Client','m-1.0.0','Mobile: Ứng dụng khởi động chậm'),
('Mobile Client','m-1.0.0','Mobile: Thông báo đẩy bị lặp'),
('Mobile Client','m-1.0.0','Mobile: Lỗi đồng bộ phiên đăng nhập'),
('ReleaseTracker Core','0.9.0','Phát hành 0.9: trang đăng nhập cơ bản'),
('ReleaseTracker Core','0.9.0','Phát hành 0.9: mật khẩu lưu dạng rõ'),
('ReleaseTracker Core','0.9.0','Phát hành 0.9: thiết lập CSDL và migration')
), ins AS (
  INSERT INTO release_items(release_id,issue_id,project_id,snapshot_title,snapshot_status,snapshot_priority,
                            snapshot_assignee,snapshot_description,snapshot_commit_url,snapshot_issue_type)
  SELECT r.id,i.id,p.id,i.title,i.status,i.priority,COALESCE(u.full_name,''),i.description,i.commit_url,i.issue_type
  FROM src s
  JOIN projects p ON p.name=s.project
  JOIN releases r ON r.project_id=p.id AND r.version_name=s.version AND r.status='planned'
  JOIN issues i ON i.project_id=p.id AND i.title=s.title
  LEFT JOIN users u ON u.id=i.assignee_id
  ON CONFLICT(release_id,issue_id) DO NOTHING
  RETURNING release_id,issue_id,project_id
)
INSERT INTO audit_log(project_id,user_id,action,entity_type,entity_id,details)
SELECT ins.project_id,r.created_by,'release_item_added','release',ins.release_id,jsonb_build_object('issue_id',ins.issue_id)
FROM ins JOIN releases r ON r.id=ins.release_id;

-- Chốt 0.9.0: planned -> ready -> released và ghi snapshot cuối cùng.
-- Cờ rt.release_transition chỉ có hiệu lực trong transaction của seed.
SELECT set_config('rt.release_transition','service',true);
UPDATE releases r SET status='ready',checked_at=now()
FROM projects p
WHERE p.id=r.project_id AND p.name='ReleaseTracker Core' AND r.version_name='0.9.0' AND r.status='planned';
UPDATE release_items ri
SET snapshot_title=i.title,snapshot_status=i.status,snapshot_priority=i.priority,
    snapshot_assignee=COALESCE(u.full_name,''),snapshot_description=i.description,
    snapshot_commit_url=i.commit_url,snapshot_issue_type=i.issue_type,
    snapshot_test_result=COALESCE(t.result,'not_tested'),snapshot_test_version=t.issue_version,
    snapshot_test_tested_at=t.tested_at
FROM releases r, issues i
LEFT JOIN users u ON u.id=i.assignee_id
LEFT JOIN LATERAL (
  SELECT tr.result,tr.issue_version,tr.tested_at FROM test_results tr WHERE tr.issue_id=i.id ORDER BY tr.id DESC LIMIT 1
) t ON true
WHERE r.id=ri.release_id AND r.version_name='0.9.0' AND r.status='ready' AND i.id=ri.issue_id;
UPDATE releases r SET status='released',released_at=now(),project_name_snapshot=p.name
FROM projects p
WHERE p.id=r.project_id AND p.name='ReleaseTracker Core' AND r.version_name='0.9.0' AND r.status='ready';
INSERT INTO audit_log(project_id,user_id,action,entity_type,entity_id,details)
SELECT r.project_id,r.created_by,'release_snapshotted','release',r.id,
       jsonb_build_object('item_count',(SELECT count(*) FROM release_items ri WHERE ri.release_id=r.id),'source','seed')
FROM releases r
WHERE r.version_name='0.9.0' AND r.status='released'
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action='release_snapshotted' AND a.entity_id=r.id);
