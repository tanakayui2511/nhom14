# ReleaseTracker

Hệ thống theo dõi lỗi và quản lý kế hoạch phát hành phần mềm — **Đề tài 5**, viết bằng PHP 8.1+ thuần và PostgreSQL (không framework, không Composer).

ReleaseTracker giúp một nhóm phát triển:

- ghi nhận lỗi/công việc, phân công, bình luận, gắn link commit/PR;
- để kiểm thử viên ghi kết quả kiểm thử và xác nhận lỗi đã sửa;
- khai báo quan hệ phụ thuộc giữa các công việc và **từ chối quan hệ tạo vòng**;
- lập phiên bản phát hành, kiểm tra **điều kiện phát hành công khai**, chốt phát hành và lưu **snapshot bất biến**;
- xuất ghi chú phát hành (Markdown/TXT) từ snapshot.

Phiên bản hiện tại: **v1.1.0** (xem [CHANGELOG.md](CHANGELOG.md)).

## Ảnh chụp màn hình

> Chỗ trống — nhóm chèn ảnh trước khi nộp.

| Màn hình | Ảnh |
| --- | --- |
| Tổng quan | `docs/screenshots/dashboard.png` |
| Danh sách lỗi có bộ lọc | `docs/screenshots/issues.png` |
| So sánh khi xung đột | `docs/screenshots/conflict.png` |
| Phụ thuộc — bị từ chối vì vòng | `docs/screenshots/cycle.png` |
| Checklist điều kiện phát hành | `docs/screenshots/release-checklist.png` |
| Ghi chú phát hành | `docs/screenshots/release-notes.png` |

## Cài đặt từng bước

### 1. Yêu cầu

- PHP **8.1+** có extension `pgsql` (`php -m | grep pgsql`).
- PostgreSQL 13+ và `psql`.
- Bash (Linux, macOS, WSL hoặc Git Bash). Khuyến nghị chạy trong WSL/Linux.

### 2. Tạo CSDL

```sql
CREATE ROLE releasetracker LOGIN PASSWORD 'doi-mat-khau-nay';
CREATE DATABASE releasetracker OWNER releasetracker;
```

### 3. Cấu hình kết nối

Cách 1 — hỏi đáp tương tác (khuyến nghị):

```bash
bash lab.sh db-config
```

Cách 2 — chép file mẫu rồi điền mật khẩu:

```bash
cp .local/db.example.json .local/db.json   # sửa PGPASSWORD
```

> **Không commit thư mục `.local/`** (chỉ file mẫu `db.example.json` được commit). Nếu mật khẩu CSDL từng bị lộ (commit nhầm, gửi qua chat…), đổi ngay bằng `ALTER ROLE releasetracker PASSWORD '...';` rồi chạy lại `bash lab.sh db-config`.

### 4. Tạo bảng, nạp dữ liệu mẫu, chạy

```bash
bash lab.sh check     # kiểm tra PHP, pgsql, psql, kết nối
bash lab.sh seed      # chạy migration + nạp dữ liệu mẫu (chạy lại được, không trùng)
bash lab.sh run       # mở http://127.0.0.1:8105 — Ctrl+C để dừng
```

`bash lab.sh migrate` chỉ chạy migration; `php tools/migrate.php --status` xem migration đã áp dụng.

## Tài khoản demo

Mật khẩu chung: **`demo1234`** (chỉ dùng cục bộ; đổi hoặc xoá trước khi công khai).

| Tài khoản | Họ tên | Dự án — vai trò | Dùng để demo |
| --- | --- | --- | --- |
| `admin_a` | Quản trị viên | Core — admin; Mobile Client — admin | Tạo/sửa dự án, quản lý thành viên, xem nhật ký |
| `dev_a` | Nguyễn An | Core — developer | Sửa lỗi, đổi trạng thái, khai báo phụ thuộc |
| `tester_a` | Trần Bình | Core — tester | Báo lỗi, ghi kết quả kiểm thử, đổi ưu tiên blocker |
| `release_a` | Lê Chi | Core — release manager | Lập phiên bản, xác nhận sẵn sàng, chốt phát hành |
| `dev_b` | Phạm Dũng | Mobile Client — developer | "Người của dự án B": không truy cập được dự án Core |

Quyền theo vai trò (admin có mọi quyền trong dự án của mình):

| Thao tác | developer | tester | release_manager | admin |
| --- | :-: | :-: | :-: | :-: |
| Tạo lỗi/công việc, bình luận | ✓ | ✓ | ✓ | ✓ |
| Sửa công việc | ✓ | chỉ việc mình báo/được giao | ✓ | ✓ |
| Đổi ưu tiên của công việc đang là **Blocker** | ✗ | ✓ | ✓ | ✓ |
| Ghi kết quả kiểm thử | ✗ | ✓ | ✓ | ✓ |
| Thêm/gỡ phụ thuộc | ✓ | ✗ | ✓ | ✓ |
| Lập phiên bản, xác nhận sẵn sàng, chốt | ✗ | ✗ | ✓ | ✓ |
| Xem nhật ký kiểm toán | ✗ | ✗ | ✓ | ✓ |
| Tạo/sửa dự án, quản lý thành viên | ✗ | ✗ | ✗ | ✓ |

## Dữ liệu mẫu

2 dự án (ReleaseTracker Core, Mobile Client), 43 công việc, 15 phụ thuộc (không có vòng), 5 phiên bản:

- Core `1.0.0` (dự kiến, đang **chưa đạt** điều kiện vì có blocker chưa kiểm thử) — demo checklist;
- Core `1.1.0`, `2.0.0` (dự kiến, trống);
- Core `0.9.0` (**đã phát hành**, có snapshot) — demo phép thử 5;
- Mobile Client `m-1.0.0` (dự kiến).

Có sẵn bình luận, kết quả kiểm thử `passed`/`failed`/`blocked` và link commit/PR mẫu.

## Điều kiện phát hành

Một phiên bản chỉ được **Xác nhận sẵn sàng** (dự kiến → sẵn sàng) và **Chốt phát hành** (sẵn sàng → đã phát hành) khi đạt tất cả điều kiện sau. Danh sách này cũng hiển thị công khai trên trang Phiên bản, và được kiểm tra lại trong cùng transaction lúc chốt.

1. **Có công việc trong phiên bản** — ít nhất một công việc.
2. **Công việc thuộc đúng dự án** — mọi item và công việc nguồn cùng dự án với phiên bản.
3. **Mọi công việc đã hoàn tất** — trạng thái `verified` (Đã xác nhận) hoặc `closed` (Đã đóng).
4. **Blocker đã kiểm thử lại hợp lệ** — mỗi công việc là blocker, *hoặc từng là blocker kể từ khi gắn vào phiên bản*, phải: ở trạng thái verified/closed, có kết quả kiểm thử mới nhất là `passed`, lần test đó làm trên đúng phiên bản dữ liệu hiện tại (không bị sửa/mở lại sau đó) và có bằng chứng nguồn gốc trong audit.
5. **Công việc tiên quyết đã hoàn tất** — toàn bộ chuỗi phụ thuộc (bắc cầu) đã closed, hoặc verified với test passed hợp lệ.
6. **Không có chu trình phụ thuộc** trong dự án.
7. **Dữ liệu snapshot hợp lệ** — tiêu đề, trạng thái, ưu tiên hợp lệ.
8. **Người thao tác có quyền phát hành** — release manager hoặc admin.

Nếu một công việc trong phiên bản "Sẵn sàng" bị sửa làm hỏng điều kiện, phiên bản **tự hạ về "Dự kiến"**.

## Kịch bản demo hai tình huống của đề

### Tình huống 1 — Lỗi → phân công → sửa → kiểm thử lại → xác nhận

1. Đăng nhập `tester_a` → **Lỗi & công việc** → "+ Báo lỗi": loại *Lỗi*, nhập bước tái hiện (bấm "Chèn mẫu" để có khung *Bước tái hiện / Kết quả mong đợi / Kết quả thực tế*), phân công cho *Nguyễn An*.
2. Đăng nhập `dev_a` → bấm **Việc của tôi** → mở lỗi → "Sửa công việc": dán link commit, chuyển trạng thái *Đã sửa*.
3. Đăng nhập `tester_a` → mở lỗi → **Ghi kết quả kiểm thử**: *Không đạt* → công việc tự trả về *Đang xử lý*.
4. `dev_a` sửa lại → *Đã sửa*; `tester_a` ghi *Đạt* → công việc thành **Đã xác nhận**.
5. Cuộn xuống **Lịch sử thay đổi**: thấy ai đổi gì, giá trị cũ → mới.
6. (Xung đột) Mở cùng công việc ở hai tab, lưu tab 1 rồi lưu tab 2 → bảng so sánh, chọn "Tải bản mới nhất" hoặc "Ghi đè có chủ đích".

### Tình huống 2 — Lập phiên bản → kiểm tra → chốt → xuất ghi chú

1. Đăng nhập `release_a` → **Phiên bản phát hành** → mở `1.0.0`: checklist có ✗ ở *Blocker đã kiểm thử lại hợp lệ*, bấm link `#id` để tới công việc gây chặn.
2. Lập phiên bản mới, thêm các công việc đã *Đã xác nhận* → **Kiểm tra lại** → 8/8 ✓.
3. **Xác nhận sẵn sàng** → trạng thái *Sẵn sàng*.
4. **Chốt phát hành…** → hộp xác nhận `<dialog>` → xác nhận.
5. **Tải Markdown** / **Tải TXT**: ghi chú nhóm theo *Tính năng / Sửa lỗi / Công việc*, có #id, tiêu đề, người phụ trách, kết quả kiểm thử khi chốt, link commit/PR.
6. Sửa tiêu đề một công việc vừa phát hành → tải lại ghi chú: **không đổi** (snapshot).

Thêm: ở trang **Phụ thuộc**, thêm quan hệ tạo vòng (ví dụ A→B, B→C rồi C→A) → bị từ chối, thông báo `#A → #B → #C → #A` và từng mắt xích được tô đậm.

## Chạy kiểm thử

```bash
bash lab.sh test
```

In kết quả từng bộ và dòng tổng `TONG_KET: x PASS / y FAIL / z test`; exit code khác 0 nếu có test hỏng.

| File | Nội dung | Cần CSDL |
| --- | --- | :-: |
| `tests/run.php` | Unit test: DFS tìm đường/vòng, các hàm `*Label()`, kiểm tra enum, lịch sử, ghi chú phát hành | ✗ |
| `tests/integration.php` | 5 phép thử bắt buộc (T1–T5), hồi quy lỗi (R1–R7), 2 tình huống đầu-cuối (S1–S2); mỗi test trong `BEGIN … ROLLBACK` | ✓ |
| `tests/regression.php` | Bộ hồi quy mở rộng, gồm test đồng thời bằng nhiều tiến trình (xung đột version, hai người chốt cùng lúc…) | ✓ |
| `tests/http.php` | Smoke test HTTP qua PHP built-in server: đăng nhập, CSRF, phân quyền, các trang | ✓ |

Test chạy trên schema tạm `rt_test_<ngẫu nhiên>` (tạo, nạp migration + seed, rồi xoá) nên không đụng dữ liệu thật và không cần quyền `CREATEDB`. Nếu không kết nối được CSDL, test in `NOT_RUN` và thoát mã 2 (không báo pass giả). Bảng đối chiếu phép thử: [docs/test-cases.md](docs/test-cases.md).

## Cấu trúc thư mục

```text
public/       Controller + giao diện (mỗi trang một file PHP), assets CSS/JS
src/          Nghiệp vụ: IssueService, DependencyService, ReleaseService, ReleaseNotes,
              ProjectService, History, Labels, App (session, CSRF, quyền, layout), Database
database/     migrations/ (001–007, chạy lại được), seed.sql, schema.sql
tests/        run.php, integration.php, regression.php, http.php + bootstrap/helper
tools/        migrate, seed, db-config, db-check, legacy-report, backup
docs/         architecture.md, test-cases.md, tài liệu thiết kế/kiểm toán
```

Kiến trúc, sơ đồ bảng, luồng chốt phát hành, khoá lạc quan và snapshot: [docs/architecture.md](docs/architecture.md).

## Bảo mật và vận hành

- Mọi truy vấn dùng `pg_query_params`; mọi POST kiểm tra CSRF; mọi dữ liệu in ra qua `h()`.
- Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS; đăng xuất bằng POST.
- Audit log chỉ ghi thêm (trigger chặn UPDATE/DELETE); không ghi mật khẩu/token.
- Trigger CSDL là lớp bảo vệ thứ hai: chặn sửa phiên bản đã chốt và `release_items` của nó, chặn chuyển trạng thái phiên bản không qua service.
- TLS tới CSDL: thêm `PGSSLMODE` (`require`/`verify-full`…) và `PGSSLROOTCERT` vào `.local/db.json`; kiểm tra bằng `SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid();`.
- Trên WSL ổ Windows (`/mnt/c`), `chmod` không có hiệu lực: nên đặt cấu hình ở `~/.config/releasetracker/db.json` và trỏ `RT_DB_CONFIG` tới đó; đặt `RT_SESSION_DIR` cho thư mục session.
- Sao lưu: `bash tools/backup.sh --dry-run` (xem chú thích trong script; hỗ trợ mã hoá `age`). Không commit file `.dump`/`.age`.
- `php tools/legacy-report.php` — báo cáo chỉ đọc các kết quả kiểm thử cũ thiếu version/bằng chứng.

## Giới hạn đã biết

- Chưa có giới hạn số lần đăng nhập sai, quên mật khẩu, quản trị người dùng toàn hệ thống (tài khoản tạo bằng SQL/seed).
- Trang quản lý dự án là `projects.php` (đề gợi ý tên `admin.php`); chỉ admin của ít nhất một dự án mới tạo được dự án mới.
- Nhật ký kiểm toán chỉ có bộ lọc cơ bản, hiển thị tối đa 200 bản ghi; lịch sử công việc hiển thị tối đa 200 thay đổi.
- "Từng là blocker" dựa trên snapshot lúc gắn vào phiên bản và audit `priority_changed`; dữ liệu đổi ưu tiên trước v1.1.0 (chưa có audit này) chỉ được nhận biết qua snapshot lúc gắn.
- Đồ thị phụ thuộc vẽ dạng lưới đơn giản; dự án rất lớn sẽ khó đọc.
- Hộp xác nhận chốt dùng `<dialog>`; trình duyệt tắt JavaScript sẽ thấy nút chốt dự phòng không có hộp xác nhận.
- Ứng dụng phục vụ học tập, chưa qua kiểm thử bảo mật/hiệu năng cho môi trường production.

## Giấy phép và ghi công

Mã nguồn phát hành theo giấy phép MIT — xem [LICENSE](LICENSE). Nguồn gốc mã và tài nguyên: [NOTICE.md](NOTICE.md).
