# ReleaseTracker — Đề tài 5 (Lab 05 mở rộng)

Ứng dụng PHP 8.1+ thuần và PostgreSQL để theo dõi lỗi/công việc, kiểm soát phụ thuộc và quản lý kế hoạch phát hành phần mềm. Dự án giữ cách tổ chức của Lab 05 (`src/`, `public/`, `database/`, `tests/`) nhưng đã bổ sung migration, phân quyền, audit log, khóa lạc quan, khóa tuần tự hóa và bộ kiểm thử tự động.

## Yêu cầu

- PHP 8.1 trở lên với extension `pgsql`.
- PostgreSQL.
- `psql` cho các lệnh `lab.sh check`, `run`, `test`, `migrate`, `seed`, `sql`. Chạy trực tiếp `php tests/run.php` và `php tests/integration.php` không cần `psql`.
- Git **không bắt buộc** để chạy ứng dụng hoặc test; chỉ cần cho version control/nộp bài. `bash lab.sh check` sẽ cảnh báo nếu thiếu Git nhưng không chặn.
- Linux/WSL được hỗ trợ chính thức. Trên Windows có thể dùng Git Bash/WSL, miễn `php` (>= 8.1, có `pgsql`) và `psql` nằm trong `PATH`.

## Cấu hình cơ sở dữ liệu

Tạo role/database riêng nếu chưa có (ví dụ):

```sql
CREATE ROLE releasetracker LOGIN PASSWORD 'mat-khau-cua-ban';
CREATE DATABASE releasetracker OWNER releasetracker;
```

Sau đó cấu hình trên máy hiện tại:

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh migrate
bash lab.sh seed
bash lab.sh run
```

Mở `http://127.0.0.1:8105`. Dừng máy chủ bằng Ctrl+C.

- `db-config` ghi cấu hình vào `.local/db.json` với quyền hạn chế; không commit thư mục `.local/`. Công cụ chỉ hỏi 5 khóa kết nối; nếu DB yêu cầu TLS, thêm `PGSSLMODE`/`PGSSLROOTCERT` vào file sau khi tạo (xem mục “Bảo vệ cấu hình và backup”).
- `migrate` chạy các file trong `database/migrations/` theo thứ tự và ghi nhận vào `schema_migrations`.
- `seed` chạy migration rồi nạp dữ liệu mẫu. Có thể chạy lại; seed dùng `ON CONFLICT` để tránh nhân đôi dữ liệu.
- `database/schema.sql` vẫn là entry point psql cho môi trường mới, gọi lần lượt ba migration.
- `bash lab.sh test` chạy cả unit/service test và HTTP integration test.
- `bash lab.sh legacy-report` chạy báo cáo chỉ đọc các test result legacy; xem mục “Báo cáo legacy test” bên dưới.

### Bảo vệ cấu hình và backup

- `.local/db.json` chứa thông tin kết nối (kể cả mật khẩu); `.local/backups/*.dump` chứa dữ liệu người dùng và hash mật khẩu. Cả hai phải nằm ngoài Git (đã có trong `.gitignore`).
- Trên WSL DrvFs (`/mnt/c`, `/mnt/d`), `chmod` không có hiệu lực và file thường hiển thị `777`. Không dựa vào `chmod` để bảo vệ secret trên ổ Windows.
- Khuyến nghị đặt cấu hình trên filesystem ext (ext2/ext3/ext4) trong WSL, ví dụ `~/.config/releasetracker/db.json` với thư mục `700` và file `600`, rồi đặt `RT_DB_CONFIG` trỏ tới file đó.
- Nếu phải giữ trên ổ Windows, giới hạn ACL bằng `icacls` cho đúng tài khoản chạy ứng dụng và kiểm tra lại bằng `icacls`.
- Backup nên được mã hóa khi lưu (ví dụ `age`/`gpg`) vì chứa hash mật khẩu và dữ liệu audit.
- **TLS:** file cấu hình hỗ trợ tùy chọn `PGSSLMODE` (`disable|allow|prefer|require|verify-ca|verify-full`) và `PGSSLROOTCERT`. Chỉ thêm biến **không** chứng minh TLS đã bật; phải kiểm tra kết nối thực tế:
  ```sql
  SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid();
  ```
  `ssl = t` mới là bằng chứng phiên hiện tại dùng TLS. Với DB ở xa, dùng `require` hoặc `verify-full` kèm root CA; `verify-full` không có CA tin cậy sẽ từ chối kết nối (đúng thiết kế).
- **Session ngoài workspace:** đặt `RT_SESSION_DIR` trỏ tới thư mục trên ext2/ext3/ext4, ví dụ `~/.local/state/releasetracker/sessions`; `lab.sh run` tạo thư mục `700`, còn file session do PHP tạo với `600`. Nếu biến không đặt, mặc định vẫn là `.local/sessions`.
- **Công cụ backup:** `bash tools/backup.sh --dry-run` để kiểm tra trước; bỏ `--dry-run` để dump. Công cụ chỉ ghi trên **ext2/ext3/ext4, xfs hoặc btrfs**; từ chối DrvFs (`/mnt/*`) và tmpfs trước khi tạo dump. Dùng `umask 077`, thư mục `700`, file `600`, custom format, SHA-256, không ghi đè; dump được kiểm tra bằng `pg_restore --list` và checksum được tính lại để so khớp sau khi ghi.
  - Mặc định **giữ plaintext** sau khi mã hóa. `--encrypt` chỉ chạy khi có `age`, recipient và identity đọc được để kiểm tra giải mã; thiếu một trong ba thì dừng trước khi dump.
  - `--delete-plaintext` chỉ dùng khi đã mã hóa, giải mã và `pg_restore -l` thành công, và cả checksum của `.age` đã khớp. Xác nhận phải nhập đúng `DELETE` tại **terminal tương tác** (`/dev/tty`); nhập qua pipe/redirect bị từ chối (exit 3) và plaintext được giữ nguyên.
  - Lỗi xác minh dump (`pg_restore -l` không đọc được, checksum lệch) **không xóa dữ liệu**: dump đã tạo xong được giữ nguyên và log ghi `PLAINTEXT_UNVERIFIED=<path>` (file checksum lệch bị bỏ). Chỉ dump dở dang do `pg_dump` lỗi mới bị xóa. Lỗi `age`/giải mã giữ nguyên plaintext đã xác minh; file `.age` chưa xác minh sẽ bị xóa.
  - Không lưu age identity cạnh backup; không commit `.dump`, `.age` hoặc `.sha256`.
- **Credential khi backup:** công cụ không dùng `PGPASSWORD`. Nó ghi một passfile tạm một dòng (`host:port:db:user:password`, escape `:` và `\`) vào thư mục `700` dưới `${RT_BACKUP_TMPDIR:-${XDG_RUNTIME_DIR:-$HOME/.cache}}/releasetracker-pass`, file `600`; chỉ `PGPASSFILE` được export cho `pg_dump`. Passfile bị xóa ngay sau `pg_dump` và bởi trap ở mọi nhánh (kể cả khi từ chối xóa plaintext). Công cụ không ghi vào `~/.pgpass`; mật khẩu chứa xuống dòng bị từ chối.
  - Nếu tiến trình bị `SIGKILL`, trap không chạy được: đặt `RT_BACKUP_TMPDIR` trên `$XDG_RUNTIME_DIR` (tmpfs) để passfile ngắn hạn hơn.
- **Test cô lập cho công cụ backup:** `bash tests/backup-isolated.sh` — tạo cụm PostgreSQL tạm dưới `$HOME/.cache`, dữ liệu giả và khóa `age` tạm; tự bỏ qua phần mã hóa nếu thiếu `age`/`python3`/`setsid`. Không đọc `.local/`, không chạm backup thật. Bộ test cũng kiểm tra credential: `pg_dump` không nhận `PGPASSWORD`, passfile `600` trong thư mục `700`, không xuất hiện trong argv/log, bị xóa sau mọi nhánh.
- **Mẫu thủ công (nếu không dùng công cụ):**
  ```bash
  umask 077
  backup="$HOME/.local/share/releasetracker/backups/communitypass_$(date -u +%Y%m%dT%H%M%SZ).dump"
  pg_dump -Fc -f "$backup"
  sha256sum "$backup" > "$backup.sha256"
  age -r "$BACKUP_RECIPIENT" -o "$backup.age" "$backup"
  age -d -i "$BACKUP_IDENTITY" "$backup.age" | pg_restore -l >/dev/null
  ```
  Khóa riêng (`age` identity/`gpg` private key) phải lưu tách biệt khỏi backup và máy chủ; định kỳ diễn tập restore vào cụm/schema cô lập.

## Tài khoản demo

Mật khẩu chung: `demo1234`.

> Cảnh báo: đây là credential demo dùng được, chỉ dành cho lab cục bộ. Trước khi công khai repo hoặc triển khai ngoài lab, phải đổi mật khẩu seed/loại bỏ tài khoản demo hoặc thay bằng secret riêng.

| Tài khoản | Vai trò |
| --- | --- |
| `admin_a` | Admin dự án |
| `dev_a` | Developer — ReleaseTracker Core |
| `tester_a` | Tester — ReleaseTracker Core |
| `release_a` | Release manager — ReleaseTracker Core |
| `dev_b` | Developer — Mobile Client |

`admin_a` được thêm vào cả hai dự án để kiểm tra luồng quản trị. Đây là tài khoản học tập, không dùng khi triển khai công khai.

## Chức năng

- Quản lý dự án, thành viên và vai trò theo từng dự án; chỉ admin dự án được tạo/sửa dự án và thay đổi thành viên.
- Tạo, sửa, bình luận lỗi/công việc; phân công, ưu tiên, bước tái hiện, commit/PR.
- Ghi kết quả kiểm thử và phát hiện kết quả `passed` đã lỗi thời so với phiên bản issue.
- Quan hệ phụ thuộc: từ chối tự phụ thuộc, trùng lặp, khác dự án và chu trình; hiển thị công việc đang bị chặn.
- Phiên bản phát hành: `planned -> ready -> released`, kiểm tra điều kiện phía server, snapshot bất biến và xuất Markdown/TXT.
- Optimistic locking bằng `issues.version`; thao tác ảnh hưởng điều kiện phát hành dùng khóa dự án `FOR UPDATE`.
- CSRF cho mọi POST, session cookie `HttpOnly`/`SameSite=Lax`/`Secure` khi HTTPS, truy vấn tham số và audit log.

## Kiểm thử

Chạy trực tiếp:

```bash
php tests/run.php
php tests/integration.php
```

Hoặc:

```bash
bash lab.sh test
```

- `tests/run.php`: 43 test service/DB, gồm seed invariant, đồ thị, migration/seed, các phép thử P1-1/P1-2/P1-3, optimistic locking tuần tự và cạnh tranh bằng hai process, transaction/race khi chốt release, phân quyền, validation, provenance của test result, quy tắc tăng version khi ghi test, cấu hình TLS, snapshot provenance, `migrate --status` chỉ đọc và legacy report.
- `tests/integration.php`: 16 test HTTP, khởi chạy PHP built-in server trên schema test; kiểm tra đăng nhập, CSRF cho mọi POST entry point, dashboard, phân quyền chéo dự án, release notes và các luồng P1-3.
- Mỗi lần chạy tạo schema riêng `rt_test_<random>` trong database đã cấu hình, nạp migration + seed rồi xóa schema khi kết thúc. Cách này không sửa dữ liệu thật và không cần quyền `CREATEDB`.
- Nếu database không sẵn sàng, test ghi `NOT_RUN` và thoát mã 2; không báo pass giả.
- Test ghi dữ liệu chỉ chạy trên schema `rt_test_*`; không chạy migration/seed/UPDATE/DELETE trên database chính.
- Seed hiện không tạo `test_results`. Issue #4 “Thông báo lỗi biểu mẫu chưa rõ” ở trạng thái `resolved`, không phải `verified`, vì chưa có bằng chứng kiểm thử. Test seed sẽ fail nếu có issue `verified` thiếu test `passed` đúng version hiện tại.

### Báo cáo legacy test

Công cụ `tools/legacy-report.php` chỉ đọc dữ liệu, không sửa issue/test/audit:

```bash
php tools/legacy-report.php
php tools/legacy-report.php --json
php tools/legacy-report.php --project=1
php tools/legacy-report.php --schema=rt_test_example --json
```

Công cụ mở `BEGIN TRANSACTION READ ONLY`, chỉ chạy `SELECT`, và từ chối ghi nếu transaction không phải read-only. Báo cáo:

- Liệt kê test result kèm issue/project, version hiện tại, `issue_version`, kết quả, thời điểm, tester, vai trò hiện tại (`tester_role_current`), vai trò tại thời điểm ghi (`recorded_role`), provenance (`audited_role`/`unverified`/`mismatch`) và test có phải mới nhất không.
- Phân loại `valid_current_pass`, `current_non_pass`, `stale_version`, `legacy_unknown_pass`, `legacy_unknown_non_pass`, `superseded`, `untrusted_current_pass`.
- Cảnh báo `VERIFIED_WITHOUT_VALID_TEST`, `BLOCKER_WITHOUT_VALID_TEST`, `LATEST_TEST_FAILED`, `LATEST_TEST_BLOCKED`, `LEGACY_TEST_VERSION_UNKNOWN`, `STALE_TEST_VERSION`, `TEST_PROVENANCE_UNVERIFIED`, `NO_TEST_RESULT`.
- Không suy đoán version của test legacy `issue_version=0`; chỉ ghi nhận là “không xác định”.
- Không dùng membership hiện tại để kết luận về nguồn gốc test lịch sử. Chỉ test có audit `test_result_recorded` ghi `tester_role` hợp lệ tại thời điểm ghi mới được coi là `audited_role`; test chèn trực tiếp hoặc legacy không có audit là `unverified`.

Trên database chính hiện tại, báo cáo dự kiến ghi nhận 5 legacy test, 3 issue `verified` thiếu bằng chứng hợp lệ và các blocker chưa có test hiện tại. Đây là cảnh báo, không phải thao tác sửa dữ liệu.

## Kiến trúc

```text
public/*.php        Controller/UI: xác thực, CSRF, phân quyền, validate, render
src/App.php         Session, CSRF, quyền theo dự án, audit, layout
src/Database.php    Kết nối, query tham số, transaction/savepoint, project lock
src/IssueService.php     Nghiệp vụ issue, comment, test result, optimistic locking
src/DependencyService.php Đồ thị phụ thuộc, phát hiện chu trình, vô hiệu release ready
src/ReleaseService.php   Điều kiện phát hành, ready/release, snapshot
src/ReleaseNotes.php     Sinh Markdown từ snapshot
database/migrations/     Migration có phiên bản, idempotent
tools/legacy-report.php  Báo cáo legacy test chỉ đọc (READ ONLY transaction)
tests/                   Bootstrap schema test, test bắt buộc, HTTP smoke, worker
```

Luồng ghi điển hình: controller kiểm tra CSRF/quyền và validate dữ liệu, service mở transaction, khóa dự án, đọc bản ghi cần thiết bằng `FOR UPDATE`, kiểm tra nghiệp vụ, ghi dữ liệu + audit trong cùng transaction, rồi commit.

## Schema và migration

- `001_initial_schema.sql`: bảng nền `users`, `projects`, `project_members`, `issues`, `dependencies`, `comments`, `test_results`, `releases`, `release_items`, `audit_log`; dùng `IF NOT EXISTS`.
- `002_integrity_columns.sql`: thêm `project_id` vào `test_results`/`release_items`, metadata snapshot, `issue_version`, khóa ngoại tổ hợp cùng dự án, unique key phục vụ khóa ngoại; kiểm tra dữ liệu chéo dự án trước khi áp dụng và không xóa dữ liệu.
- `003_release_guards.sql`: trigger chặn sửa/xóa release `released` và chặn thay đổi `release_items` sau khi chốt; là lớp bảo vệ thứ hai sau transaction của service.
- `database/seed.sql`: 2 dự án, 40 issue, 15 dependency, 3 release và 5 tài khoản demo. Seed không tạo `test_results` và không có issue `verified`; issue #4 được seed ở trạng thái `resolved`.

Khi cần thay đổi schema, thêm migration mới; không sửa migration đã áp dụng trên môi trường đang chạy. `php tools/migrate.php --status` xem trạng thái, `--dry-run` xem migration chờ chạy. Hai chế độ này chỉ đọc: không tạo bảng `schema_migrations`, không áp dụng migration.

## Quyết định kỹ thuật quan trọng

- **Khóa tuần tự hóa theo dự án:** mọi thao tác có thể làm thay đổi điều kiện phát hành (sửa issue, test result, dependency, release) đều khóa `projects` trước, sau đó khóa release nếu cần. Thứ tự khóa thống nhất giúp tránh deadlock và race.
- **Optimistic locking:** `issues.version` tăng trong cùng câu `UPDATE ... WHERE version=$n`; nếu affected rows khác 1 thì trả xung đột, không ghi đè.
- **Snapshot bất biến:** khi release, service ghi lại dữ liệu issue vào `release_items`; trigger database chặn sửa sau khi release. Release notes đọc từ snapshot, không đọc issue hiện tại. Snapshot chỉ ghi kết quả test có provenance hợp lệ; test thiếu bằng chứng được ghi `unverified` kèm version để truy vết và không bao giờ được trình bày như `passed`.
- **Quy tắc version khi ghi test:** mỗi lần `recordTest` (passed/failed/blocked) tăng `issues.version` đúng 1 và lưu `test_results.issue_version` bằng version mới. `passed` chuyển `verified`; `failed`/`blocked` chuyển `retest`. Vì vậy cùng một issue, ghi passed trực tiếp từ v2 ra v3; nếu đã ghi failed trước đó thì passed kế tiếp ra v4. Snapshot dùng đúng `issue_version` của test mới nhất.
- **Test result gắn version và provenance:** mỗi test result lưu `issue_version`; blocker và dependency `verified` chỉ hợp lệ nếu kết quả mới nhất là `passed`, đúng version hiện tại và có audit `test_result_recorded` chứng minh vai trò tester/release_manager/admin tại thời điểm ghi. Test legacy/không có audit bị coi là `unverified`, không tự động hợp thức hóa. Trạng thái `verified` chỉ được đặt bằng biểu mẫu ghi kết quả kiểm thử, không đặt qua biểu mẫu cập nhật.
- **Cách ly test:** dùng schema riêng thay vì database riêng vì role ứng dụng không có `CREATEDB`; schema được xóa sau test, không ảnh hưởng dữ liệu thật.
- **Audit an toàn:** chỉ ghi ID, trạng thái, số lượng và metadata nghiệp vụ; không ghi mật khẩu, session token hoặc CSRF token.

## Kịch bản demo

### 1. Blocker chặn phát hành và retest

1. Đăng nhập `dev_a`, tạo issue priority `blocker`.
2. Đăng nhập `release_a`, tạo phiên bản, gắn issue, bấm chuyển sang sẵn sàng: hệ thống từ chối vì chưa có test.
3. Đăng nhập `tester_a`, ghi kết quả `failed`; thử ready: vẫn bị chặn.
4. Ghi kết quả `passed`; ready thành công.
5. Sửa nội dung issue (làm version đổi), thử ready lại: kết quả passed bị coi là lỗi thời.
6. Retest và chốt phát hành; xem lịch sử audit.

### 2. Snapshot và release notes bất biến

1. Tạo issue, thêm vào phiên bản, chuyển ready và chốt phát hành.
2. Tải ghi chú Markdown.
3. Sửa tiêu đề/mô tả/commit URL của issue nguồn.
4. Tải lại ghi chú: nội dung vẫn là snapshot cũ; sửa trực tiếp `release_items` bị trigger từ chối.

## CI đề xuất (chưa cấu hình, chưa chạy)

Workspace hiện chưa có workflow CI và chưa có kết quả CI thực tế. Cấu hình dưới đây là đề xuất để chạy trên GitHub Actions; không tuyên bố đã hoạt động cho đến khi pipeline thực sự chạy pass.

```yaml
name: releasetracker-ci
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: releasetracker
          POSTGRES_USER: releasetracker
          POSTGRES_PASSWORD: test-password
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U releasetracker -d releasetracker"
          --health-interval 5s --health-timeout 5s --health-retries 20
    env:
      PGHOST: 127.0.0.1
      PGPORT: '5432'
      PGDATABASE: releasetracker
      PGUSER: releasetracker
      PGPASSWORD: test-password
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pgsql, pdo_pgsql
      - run: sudo apt-get update && sudo apt-get install -y postgresql-client
      - run: bash lab.sh check
      - run: bash lab.sh test
```

Yêu cầu CI: PHP >= 8.1 có `pgsql`, PostgreSQL đang chạy, `psql` cho `lab.sh check/test`, và biến môi trường `PG*` trỏ tới database test. Không dùng mật khẩu thật trong workflow; mật khẩu trên chỉ là ví dụ cục bộ.

## Giới hạn còn tồn tại

- Seed fix (issue #4 sang `resolved`) chỉ áp dụng cho schema mới. Database đã tồn tại vẫn giữ dữ liệu cũ do seed dùng `ON CONFLICT`; cần quyết định thủ công nếu muốn sửa dữ liệu đang chạy.
- Chưa có rate limit đăng nhập, quên mật khẩu, email hoặc quản trị người dùng toàn hệ thống.
- Audit log chỉ có bộ lọc cơ bản và giới hạn 200 bản ghi gần nhất.
- Chưa có giao diện quản lý migration; dùng CLI.
- Đây là ứng dụng phục vụ học tập, chưa qua review bảo mật/production hardening.

## Giấy phép

Giữ giấy phép MIT trong `LICENSE`. Kiểm tra và ghi công các nguồn bổ sung trước khi phân phối.
