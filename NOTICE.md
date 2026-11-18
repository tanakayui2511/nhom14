# NOTICE — Nguồn gốc mã và tài nguyên

ReleaseTracker được phát hành theo giấy phép MIT (xem `LICENSE`).

## Mã nguồn gốc

- **Lab 05 — FIT4111 Teaching Examples** (bản quyền © 2026 FIT4111 Teaching Examples Contributors, giấy phép MIT).
  Dự án kế thừa cấu trúc thư mục (`src/`, `public/`, `database/`, `tests/`), script `lab.sh`, các hàm tiện ích kết nối PostgreSQL (`src/Database.php`), công cụ `tools/db-config.php`, `tools/db-check.php`, `tools/migrate.php`, `tools/seed.php` và khung test (`tests/helper.php`). Các file đặc thù của lab CommunityPass (đăng ký sự kiện) đã được gỡ bỏ.
- Phần nghiệp vụ theo dõi lỗi, đồ thị phụ thuộc, điều kiện phát hành, snapshot, giao diện và bộ kiểm thử do nhóm **Nhom14** phát triển cho Đề tài 5.

## Tài nguyên bên thứ ba

| Tài nguyên | Nguồn | Giấy phép | Cách dùng |
| --- | --- | --- | --- |
| Phông chữ **Be Vietnam Pro** | Google Fonts (`fonts.googleapis.com`) | SIL Open Font License 1.1 | Tải qua CDN khi mở trang, không đóng gói trong repo |
| Biểu tượng (logo, icon điều hướng, minh họa trạng thái rỗng) | Nhóm tự vẽ bằng SVG nội tuyến trong `src/App.php`, `public/*.php` | MIT (theo dự án) | Nhúng trực tiếp trong HTML |

Dự án **không** dùng thư viện PHP/JS bên ngoài, framework hay Composer. Thuật toán kiểm tra vòng (DFS) và kiểm tra điều kiện phát hành được viết tay trong `src/`.

## Dữ liệu mẫu

Tên người, dự án, công việc và link commit/PR trong `database/seed.sql` là dữ liệu giả lập phục vụ học tập (các link trỏ tới `github.com/example/...` không có thật).
