# UI design: Dependency Graph va Release Readiness Dashboard

Tai lieu nay la thiet ke va ghi chu trien khai Dependency Graph. Graph da duoc
tich hop vao route hien co; khong them route API, schema hoac migration moi.
Release Readiness Dashboard van o giai doan thiet ke va chua trien khai.

## Dependency Graph

### Du lieu va ranh gioi

- Nguon du lieu la cac service hien co: `DependencyService::listForProject`,
  `blockedForProject` va `projectCycle`.
- Chi hien thi issue trong project hien tai. Khong truy van hoac ve canh sang
  project khac.
- Khong tao so lieu tong hop moi trong database. Neu can JSON cho client, route
  se tai dung ket qua service hien co va escaping theo context.

### Hien thi

- Node la issue, hien thi ma issue, tieu de, status va priority.
- Edge co huong `parent -> child` theo quy uoc cua dependency hien tai.
- Mau node chi la bo tro; text status va icon phai van doc duoc khi khong co
  mau. Blocker dung nhan ro rang, cycle dung canh bao ro rang.
- Bo loc: status, priority, chi blocker va chi nhanh lien quan den issue dang
  xem.
- Khi khong co JavaScript hoac graph qua lon, hien thi bang dependency hien co
  kem lien ket toi issue thay cho canvas.
- Hanh dong cua node mo trang chi tiet issue; khong cho sua dependency truc tiep
  tren graph trong phien ban dau.

### Kiem thu chap nhan

- Khong lo issue ngoai project.
- Cycle va blocker duoc danh dau bang text, khong phu thuoc rieng vao mau.
- Graph rong, graph mot node va graph co cycle deu co trang thai hien thi hop le.
- Ban phim co the di qua node va mo chi tiet; man hinh nho khong bi tran ngang.

## Release Readiness Dashboard

### Nguon du lieu

- Dung `ReleaseService::check` lam nguon quyet dinh duy nhat cho readiness.
- Tai su dung release/project context cua `public/releases.php` va cac dieu kien
  da co: item status, blocker, dependency closure, cycle, project, snapshot,
  test/check va role.
- Khong hien thi "ready" tu mot tham so client va khong tao metric gia. Khi
  check loi, hien thi trang thai khong xac dinh kem thong bao loi.

### Bo cuc du kien

1. Header: ten release, project snapshot, status hien tai va `checked_at`.
2. Thanh tong quan: tong item, item da hoan tat, blocker con lai, dependency
   loi va so dieu kien dat/khong dat.
3. Danh sach dieu kien: moi dieu kien co trang thai text (`Dat`, `Chua dat`,
   `Khong xac dinh`) va lien ket toi issue/release lien quan neu co.
4. Khu vuc hanh dong: chi hien thi hanh dong theo role va ket qua
   `ReleaseService::check`; khong bypass gate bang hidden field.

### Kiem thu chap nhan

- Cung mot release va cung mot database state phai cho cung ket qua voi
  `ReleaseService::check`.
- Blocker, cycle, dependency khong hop le, snapshot thieu va role khong hop le
  duoc phan biet ro rang.
- Readiness khong tu dong chuyen trang thai release; viec chuyen trang thai van
  di qua service va database gate.
- Co empty state cho release chua co item va error state cho loi truy van.
- Hien thi tot tren man hinh nho, dung heading/landmark va khong chi dung mau de
  truyen dat trang thai.

## Trang thai trien khai

- Dependency Graph: da trien khai trong `public/dependencies.php` va
  `public/assets/css/app.css`, dung service/query project-scoped hien co.
- Readiness Dashboard: chua trien khai; tiep tuc sau khi cac yeu cau DBA duoc
  giai quyet.

## Ke hoach file khi trien khai

- Giu route hien tai trong `public/dependencies.php` va `public/releases.php`,
  chi tach component khi markup lap lai thuc su can.
- Them CSS/JS nho, khong dua them framework. Graph co fallback bang bang HTML.
- Them test view/service cho project isolation, cycle, blocker va cac trang thai
  readiness. Test database runtime chi chay sau khi DBA cap provisioning.

## Thu tu thuc hien

1. Cho DBA xac nhan role, ownership, membership va quyen `GRANT/REVOKE`.
2. Hoan tat release executor va kiem thu runtime permissions.
3. Trien khai graph va dashboard tren cac service hien co.
4. Chay regression suite va kiem tra accessibility truoc khi phat hanh.
