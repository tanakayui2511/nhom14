# Kiến trúc ReleaseTracker

Tài liệu dành cho người đọc mã: cách tổ chức, sơ đồ bảng, luồng chốt phát hành và hai cơ chế cốt lõi — khoá lạc quan (optimistic locking) và snapshot.

## 1. Tổ chức mã

```text
Trình duyệt ──HTTP──► public/*.php  (controller + view)
                         │  requireLogin, checkCsrf, selectProject, render bằng h()
                         ▼
                      src/*Service.php  (nghiệp vụ, mỗi thao tác ghi = 1 transaction)
                         │  queryParams (pg_query_params), audit()
                         ▼
                      PostgreSQL  (ràng buộc, khoá ngoại tổ hợp, trigger bảo vệ)
```

| Thành phần | Trách nhiệm |
| --- | --- |
| `src/App.php` | Session, CSRF, kiểm tra quyền theo dự án, `audit()`, `selectProject()`, layout (`pageStart/pageEnd`), hằng enum |
| `src/Database.php` | Kết nối, `queryParams`, `transaction()` (tự dùng SAVEPOINT khi đã ở trong transaction), `projectLock()` |
| `src/IssueService.php` | Tạo/sửa công việc có version, bình luận, ghi kết quả kiểm thử, đổi ưu tiên, lịch sử, tra ngược phiên bản |
| `src/DependencyService.php` | Đồ thị phụ thuộc: DFS tìm đường/vòng (hàm thuần), thêm/gỡ quan hệ, danh sách việc bị chặn, hạ phiên bản ready |
| `src/ReleaseService.php` | Điều kiện phát hành (`conditions`, `check`), tạo phiên bản, thêm/gỡ item, `markReady`, `release` (chốt + snapshot) |
| `src/ReleaseNotes.php` | Dựng ghi chú Markdown/TXT chỉ từ snapshot, xuất file có kiểm tra quyền |
| `src/Labels.php`, `src/History.php` | Hàm thuần: nhãn tiếng Việt, badge, mô tả lịch sử từ audit |
| `database/migrations/` | 001–007, mỗi file chạy lại được (`IF NOT EXISTS`, kiểm tra constraint trước khi thêm) |

Mọi thao tác ghi đi theo cùng một khuôn: controller kiểm tra CSRF và đăng nhập → service mở transaction → khoá dự án (`SELECT … FROM projects … FOR UPDATE`) → kiểm tra lại quyền **trong** transaction (chống TOCTOU khi vai trò bị đổi giữa chừng) → đọc bản ghi cần sửa bằng `FOR UPDATE` → kiểm tra nghiệp vụ → ghi dữ liệu + audit → commit. Thành công thì controller redirect (Post/Redirect/Get) kèm flash message.

## 2. Sơ đồ bảng

```mermaid
erDiagram
    users ||--o{ project_members : "tham gia"
    projects ||--o{ project_members : "có"
    projects ||--o{ issues : "chứa"
    users ||--o{ issues : "báo cáo / phụ trách"
    issues ||--o{ dependencies : "issue_id (phụ thuộc)"
    issues ||--o{ dependencies : "depends_on_id (tiên quyết)"
    issues ||--o{ comments : "có"
    issues ||--o{ test_results : "được kiểm thử"
    projects ||--o{ releases : "có"
    releases ||--o{ release_items : "gồm"
    issues ||--o{ release_items : "được chụp snapshot"
    projects ||--o{ audit_log : "ghi nhận"

    users {
        bigint id PK
        varchar username UK
        varchar full_name
        text password_hash
    }
    projects {
        bigint id PK
        varchar name UK
        text description
    }
    project_members {
        bigint project_id PK,FK
        bigint user_id PK,FK
        varchar role "developer|tester|release_manager|admin"
    }
    issues {
        bigint id PK
        bigint project_id FK
        varchar title "unique trong dự án"
        text description
        text reproduction_steps
        varchar issue_type "bug|task|feature"
        varchar priority "blocker|critical|high|medium|low"
        varchar status "open|in_progress|resolved|retest|verified|closed"
        bigint reporter_id FK
        bigint assignee_id FK
        text commit_url
        int version "khoá lạc quan"
    }
    dependencies {
        bigint issue_id PK,FK
        bigint depends_on_id PK,FK
        bigint issue_project_id "CHECK = depends_on_project_id"
        bigint depends_on_project_id
    }
    comments {
        bigint id PK
        bigint issue_id FK
        bigint user_id FK
        text body
    }
    test_results {
        bigint id PK
        bigint issue_id FK
        bigint project_id FK
        bigint tester_id FK
        varchar result "passed|failed|blocked"
        int issue_version "version công việc tại lúc test"
        text notes
    }
    releases {
        bigint id PK
        bigint project_id FK
        varchar version_name "unique trong dự án"
        varchar status "planned|ready|released"
        timestamptz checked_at
        timestamptz released_at
        text project_name_snapshot
    }
    release_items {
        bigint id PK
        bigint release_id FK
        bigint issue_id FK
        bigint project_id FK
        varchar snapshot_title
        varchar snapshot_status
        varchar snapshot_priority
        varchar snapshot_issue_type
        varchar snapshot_assignee
        text snapshot_commit_url
        varchar snapshot_test_result
        int snapshot_test_version
    }
    audit_log {
        bigint id PK
        bigint project_id FK
        bigint user_id FK
        varchar action
        varchar entity_type
        bigint entity_id
        jsonb details
    }
```

Ràng buộc đáng chú ý:

- Khoá ngoại tổ hợp `(issue_id, project_id) → issues(id, project_id)` trên `test_results`, `dependencies`, `release_items`: CSDL tự từ chối dữ liệu chéo dự án.
- `dependencies`: `CHECK (issue_id <> depends_on_id)` và `CHECK (issue_project_id = depends_on_project_id)`. Kiểm tra vòng làm ở PHP (DFS) vì cần trả về chuỗi gây vòng.
- Trigger: `audit_log` chỉ ghi thêm; `releases` ở `released` không sửa/xoá được; `release_items` chỉ thêm/xoá khi phiên bản `planned`, không sửa khi đã `released`; đổi `releases.status` chỉ được khi service bật cờ `rt.release_transition` trong transaction.

## 3. Kiểm tra vòng phụ thuộc

Cạnh `A → B` nghĩa là "A phụ thuộc B". Thêm cạnh `X → Y` tạo vòng khi và chỉ khi từ `Y` đã đi tới được `X`. `DependencyService::cycleIfAdded()` chạy DFS (ngăn xếp tường minh, đánh dấu đỉnh đã thăm, O(V+E)) từ `Y`; nếu tìm thấy đường `Y … X` thì chuỗi vòng là `Y … X → Y`. Ví dụ đã có A→B, B→C, thêm C→A: từ A tới được C qua B, nên thông báo là `#A → #B → #C → #A`.

Để hai request không cùng lúc mỗi bên thêm "một nửa" vòng, `add()` giữ `pg_advisory_xact_lock(project_id)` rồi mới đọc đồ thị và kiểm tra.

## 4. Luồng chốt phát hành

```mermaid
stateDiagram-v2
    [*] --> planned: Lập phiên bản
    planned --> planned: Thêm / gỡ công việc
    planned --> ready: Xác nhận sẵn sàng (check() đạt)
    ready --> planned: Công việc/phụ thuộc bị sửa (tự hạ)
    ready --> released: Chốt phát hành (check() lại + snapshot)
    released --> [*]
```

```mermaid
sequenceDiagram
    actor RM as Release manager
    participant C as releases.php
    participant S as ReleaseService::release
    participant DB as PostgreSQL
    RM->>C: POST action=release (+CSRF)
    C->>S: release(releaseId, projectId, userId)
    S->>DB: BEGIN
    S->>DB: SELECT … FROM projects WHERE id=? FOR UPDATE
    S->>DB: kiểm tra vai trò trong transaction
    S->>DB: SELECT … FROM releases WHERE id=? FOR UPDATE
    Note over S,DB: Người thứ hai bấm chốt sẽ chờ ở đây,<br/>sau đó thấy status = released và bị từ chối
    S->>S: status phải là ready
    S->>DB: check(): item, blocker, test, phụ thuộc bắc cầu, vòng, quyền
    alt còn vấn đề
        S-->>C: ValidationException (ROLLBACK)
    else đạt
        loop mỗi item
            S->>DB: UPDATE release_items SET snapshot_… (phải đúng 1 dòng)
        end
        S->>DB: UPDATE releases SET project_name_snapshot=…
        S->>DB: set_config('rt.release_transition','service', true)
        S->>DB: UPDATE releases SET status='released' WHERE status='ready'
        alt 0 dòng bị UPDATE
            S-->>C: ConflictException (ROLLBACK)
        else 1 dòng
            S->>DB: audit release_snapshotted
            S->>DB: COMMIT
            S-->>C: OK → redirect + flash
        end
    end
```

Khi một công việc, kết quả kiểm thử hoặc phụ thuộc thay đổi, `DependencyService::invalidateReadyReleases()` tìm (bằng CTE đệ quy) mọi phiên bản `ready` chứa công việc đó hoặc công việc phụ thuộc vào nó, và hạ về `planned` trong cùng transaction.

### Quy tắc blocker (điều kiện 4)

Một item bị coi là blocker nếu ưu tiên hiện tại là `blocker`, **hoặc** `snapshot_priority` lúc gắn vào phiên bản là `blocker`, **hoặc** audit có bản ghi `priority_changed` từ/thành `blocker` sau bản ghi `release_item_added` của item. Blocker đạt khi: trạng thái verified/closed, kết quả kiểm thử mới nhất là passed, `test_results.issue_version = issues.version` và bản ghi audit `test_result_recorded` khớp người ghi, kết quả, version và vai trò hợp lệ lúc ghi.

## 5. Khoá lạc quan (optimistic locking)

Bài toán: hai người mở cùng một công việc, cả hai sửa rồi lưu. Không khoá thì người lưu sau âm thầm ghi đè người lưu trước.

Cơ chế: mỗi công việc có cột `version`. Form sửa gửi kèm version người dùng nhìn thấy. Câu lệnh ghi là:

```sql
UPDATE issues SET …, version = version + 1
WHERE id = $id AND project_id = $project AND version = $version_da_thay
RETURNING version;
```

- Nếu version trong CSDL đã khác (người khác vừa lưu) thì 0 dòng bị cập nhật → `VersionConflictException`. Không có gì bị ghi đè.
- Exception mang theo bản hiện tại trong CSDL; giao diện hiển thị hai bản cạnh nhau, cho chọn **Tải bản mới nhất** hoặc **Ghi đè có chủ đích** (gửi lại bản của mình với version mới; audit ghi `overwrite_after_conflict: true`). Nếu lại có người lưu chen vào, hệ thống báo xung đột lần nữa.
- "Lạc quan" vì không giữ khoá trong lúc người dùng đang gõ; chỉ kiểm tra lúc lưu. Trong lúc lưu, service vẫn khoá dòng `FOR UPDATE` để các bước kiểm tra và ghi là nguyên tử.
- Ghi kết quả kiểm thử cũng dùng version: kết quả gắn với `issue_version`; sửa công việc sau đó làm tăng version nên kết quả passed cũ tự trở thành "lỗi thời" và không còn được tính cho điều kiện phát hành.

## 6. Snapshot

Bài toán: sau khi phát hành, công việc vẫn tiếp tục được sửa (đổi tiêu đề, mở lại…), nhưng danh sách "đã phát hành trong bản 1.2.0" và ghi chú phát hành phải giữ nguyên như lúc chốt.

Cơ chế:

- Lúc chốt, trong cùng transaction với `check()`, service chép các trường cần thiết của từng công việc vào `release_items.snapshot_*` (tiêu đề, trạng thái, ưu tiên, loại, người phụ trách, mô tả, link commit/PR, kết quả kiểm thử đã được xác minh nguồn gốc và version của lần test), và chép tên dự án vào `releases.project_name_snapshot`.
- Ghi chú phát hành (`ReleaseNotes`) và trang phiên bản đã chốt **chỉ đọc snapshot**, không join sang `issues` hay `projects`.
- Trigger CSDL chặn mọi `UPDATE/DELETE` trên `release_items` của phiên bản `released`, chặn `INSERT` khi phiên bản không còn `planned`, và chặn sửa chính dòng `releases` đã `released` — kể cả khi ai đó chạy SQL trực tiếp.
- Kết quả kiểm thử không có bằng chứng nguồn gốc hợp lệ được chụp thành `unverified`, không bao giờ hiển thị như `passed`.
- Tra ngược "công việc này từng nằm trong những bản phát hành nào" đọc từ `release_items` của các phiên bản `released`.
