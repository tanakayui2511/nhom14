## Thay đổi gì và vì sao

<!-- Tóm tắt; liên kết issue: "Sửa #123" -->

## Loại thay đổi

- [ ] Sửa lỗi
- [ ] Tính năng mới
- [ ] Giao diện
- [ ] Tài liệu / test
- [ ] Migration CSDL (file mới trong `database/migrations/`, chạy lại được)

## Cách kiểm tra

<!-- Các bước demo thủ công, tài khoản dùng để thử. -->

## Danh sách tự kiểm

- [ ] `php -l` sạch cho các file đã sửa
- [ ] `bash lab.sh test` PASS (dán dòng `TONG_KET`)
- [ ] Mọi truy vấn dùng `queryParams` (truy vấn có tham số)
- [ ] Mọi POST gọi `checkCsrf()`; mọi dữ liệu in ra đi qua `h()`
- [ ] Giá trị enum hiển thị qua hàm `*Label()`; thông báo bằng tiếng Việt
- [ ] Không làm hỏng 5 phép thử bắt buộc (T1–T5 trong `tests/integration.php`)
- [ ] Không commit `.local/`, mật khẩu, file `.dump`
- [ ] Cập nhật README / CHANGELOG / docs nếu cần
