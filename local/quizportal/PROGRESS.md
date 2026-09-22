# local_quizportal — Tiến độ

Cổng luyện thi TOEIC cho **học viên**, thay giao diện Boost mặc định bằng hệ
thống thiết kế riêng. Phần thiết kế (bảng màu, typography, ẩn dụ "vé/phiếu báo
thi", cấu trúc 7 Part) nằm ở `../../moodle-dashboard-design-notes.md` — file này
chỉ theo dõi **tiến độ triển khai**, không chép lại thiết kế.

Cập nhật lần cuối: 2026-09-22

---

## 1. Màn hình

| # | Màn hình | File | Trạng thái |
|---|---|---|---|
| 1 | Đăng nhập | `login.php` | ✅ Xong — đã nối auth thật |
| 2 | Danh sách bài thi (dashboard) | `index.php`, `templates/dashboard.mustache` | ✅ Xong — đã nối quiz thật |
| 3 | Trang làm bài | `attempt.php`, `templates/exam_*.mustache`, `amd/src/exam.js` | ✅ Xong 2026-09-22 — phần nghe liền mạch theo đồng hồ server, trang chuyển tiếp, phần đọc có phiếu trả lời. Chi tiết: `CLAUDE.md` mục 3 "A6 + A2" |
| 4 | Trang kết quả | `review.php`, `templates/result_*.mustache`, `amd/src/result.js` | ✅ Xong 2026-09-22 — phiếu báo điểm 10–990 (quy đổi sửa được ở `scale.php`), theo Part, từng câu đúng/sai/bỏ trống, lời thoại, nghe lại từng đoạn. Dashboard hiện điểm TOEIC. Chi tiết: `CLAUDE.md` mục 3 "A7 + A3" |
| 5 | *Tiếp theo* | — | ⬜ Trang hồ sơ học viên (biểu đồ tiến bộ, điểm mạnh yếu theo Part) — `result::for_attempts()` đã chấm được hàng loạt lượt làm bằng một câu SQL |

**Phụ thuộc theme (từ 2026-09-18):** `styles.css` nay lấy bảng màu từ
`theme_ptedu` qua các custom property `--pt-*`, và không còn khai báo `!important`
nào (cap `.login-container` 500px của Boost được gỡ ở tầng theme). Mỗi token vẫn
kèm giá trị dự phòng nên trang không vỡ nếu đổi theme, nhưng màu sẽ lệch.

Cổng được gắn vào site qua `$CFG->alternateloginurl` ở `config.php:24` — đây là
**tham chiếu duy nhất tới plugin từ bên ngoài**. Mọi truy cập ẩn danh đều được
dẫn về `local/quizportal/login.php` (đã kiểm chứng: `index.php` → `login/index.php`
→ portal login).

## 2. Dữ liệu: thật hay giả lập

| Thành phần | Nguồn | Ghi chú |
|---|---|---|
| Danh sách đề | `enrol_get_users_courses()` + `get_fast_modinfo()` | Thật. Tôn trọng `$cm->uservisible` nên đã tính cả quyền, ẩn/hiện và điều kiện truy cập |
| Trạng thái đề | `quiz.timeopen` / `timeclose` + `quiz_get_user_attempts()` | Thật. 4 trạng thái `completed` / `open` / `upcoming` / `closed`; `completed` được ưu tiên trước khung thời gian |
| Số lượt làm | `quiz_get_user_attempts(..., false)` | Thật. Tham số `false` loại bỏ preview attempt của giáo viên |
| Điểm | `mdl_quiz_grades` | Thật |
| Thống kê đầu trang | tính từ danh sách trên | Thật. Điểm trung bình chỉ hiện dạng `x / y` khi mọi đề cùng thang điểm |
| **Thanh 7 Part** | tag `part1`..`part7` trong Question Bank | ⚠️ **Phụ thuộc dữ liệu.** Code đọc tag qua `core_tag_tag::get_items_tags()`; nếu chưa câu hỏi nào được gắn tag thì thanh **bị ẩn** thay vì bịa tỉ lệ. Hiện Question Bank nhiều khả năng chưa gắn tag → thanh chưa xuất hiện. Đây là việc nhập liệu, không phải lỗi code |
| Tên lớp ở header | `$payload['courses']` | Thật, nhưng **chỉ hiện khi học viên ghi danh đúng 1 khoá**; ghi danh nhiều khoá thì ẩn tag lớp |
| Khối bên trái trang login ("KỲ THI", "LỚP ĐANG MỞ: Cơ bản K09", bảng đáp án) | hardcode | 🎨 Cố ý — là trang trí thị giác, có `aria-hidden="true"` |

## 3. Đợt rà soát 2026-09-18

| | Vấn đề | Trạng thái |
|---|---|---|
| **A** | `login.php` không kiểm tra `$user->confirmed`. `authenticate_user_login()` lo khoá tài khoản / `suspended` / ghi event `user_login_failed`, nhưng **không** kiểm tra `confirmed` — core làm việc đó ở `login/index.php`. Tài khoản chưa xác nhận email vẫn vào được cổng | ✅ Đã sửa |
| **B** | `\core\session\manager::validate_login_token()` **trả `true` ngay lập tức** khi `$CFG->alternateloginurl` được đặt (`lib/classes/session/manager.php:1288`). Vì cổng này dùng `alternateloginurl`, phép kiểm tra token cũ là no-op → form đăng nhập không có bảo vệ CSRF | ✅ Đã sửa — token tự quản trong `$SESSION`, so sánh `hash_equals()`, dùng một lần. Đã test: token sai bị chặn, token đúng đi tiếp tới xác thực, dùng lại token cũ bị chặn |
| **C** | Checkbox "Ghi nhớ đăng nhập" render ra nhưng không nơi nào đọc | ✅ Đã sửa — theo đúng quy tắc cookie của `login/index.php` (`nolastloggedin` / `rememberusername`), và tự tick sẵn khi username được khôi phục từ cookie |
| **D** | Luôn redirect cứng về `index.php`, bỏ qua `$SESSION->wantsurl` → deep-link tới một quiz bị mất đích đến sau khi đăng nhập | ✅ Đã sửa. Cố ý **không** dùng `core_login_get_return_url()`: nhánh fallback của nó viết lại URL về trang chủ / Dashboard của Boost — đúng thứ cổng này đang thay thế. Học viên phải về danh sách đề |
| **E** | Design notes quy định Lora + Be Vietnam Pro, nhưng `styles.css` không nạp font nào; trang login dùng `font-family: serif`, dashboard khai `'Be Vietnam Pro'` mà font không tồn tại → cả hai trang rơi về font hệ thống | ✅ Đã sửa — **tự host**, nay nằm trong `theme/ptedu/fonts/` (15 file woff2, ~250 KB, subset vietnamese + latin + latin-ext). Không gọi Google Fonts: phòng thi nội bộ không chắc có internet, mất CDN là mất đúng font đúng lúc cần nhất |
| **F** | `login.php` hardcode tiếng Việt **không dấu** ("Dang nhap", "Tai khoan khach...") trong khi `dashboard.mustache` có dấu đầy đủ | ✅ Đã sửa |
| **G** | `README.md` lọt một dấu backtick (`moodled\`ev.io`); `config-dist.php` bị sửa thành thông số WAMP thật. Đây là file mẫu phân phối của Moodle, giá trị thật đã nằm đúng chỗ ở `config.php` | ✅ Đã revert |

## 4. Nợ kỹ thuật

Chưa chặn việc gì, xử lý khi thuận tiện:

- **Chuỗi hardcode.** `lang/en/local_quizportal.php` chỉ có 5 chuỗi tiếng Anh và
  **không chuỗi nào được dùng**; toàn bộ UI viết thẳng tiếng Việt trong PHP và
  Mustache. Site đang chạy gói ngôn ngữ tiếng Việt nên các `get_string()` của
  core (ví dụ `invalidlogin`) đã ra tiếng Việt sẵn — chỉ phần chuỗi riêng của
  plugin là chưa tách ra `lang/vi`.
- **`login.php` dựng HTML bằng `html_writer`** thay vì Mustache template +
  renderer như dashboard. Hai màn hình đang theo hai kiến trúc khác nhau.
- **Nhánh chết trong JS lọc tab** (`templates/dashboard.mustache`):
  `(filter === 'all' && status === 'closed')` không bao giờ đổi kết quả vì
  `filter === 'all'` đã đúng ở vế đầu.
- **`version.php` thiếu** `$plugin->maturity` và `$plugin->release`.
- ~~**`percent` của mỗi Part tính trên tổng số slot**~~ — **đã sửa 2026-09-21.**
  Nay chỉ đếm slot `length > 0` (bỏ khối ngữ liệu `description`), và chỉ hiện
  thanh khi **mọi** câu thật đều có tag `partN` — gắn thiếu thì ẩn thanh, không vẽ
  tỉ lệ sai. Cùng lỗi này còn làm dashboard ghi "242 câu" thay vì 200.
- ~~Không có `db/`, `settings.php`, `db/access.php`~~ — **đã có từ 2026-09-21**
  (bảng `slotmeta`, capability `local/quizportal:importtests`, mục "Nhập đề TOEIC"
  trong Quản trị › Khoá học). Dòng dưới là ghi chú gốc: plugin chưa có capability
  hay trang cấu hình riêng. Chấp nhận được ở quy mô hiện tại.

## 5. Cách kiểm tra

Đã tự động kiểm chứng được (không cần tài khoản thật):

```bash
# Trang login lên 200, có token, tiếng Việt không vỡ
curl -s http://localhost/moodle/local/quizportal/login.php | grep -o 'name="logintoken" value="[^"]*"'

# CSS và font được phục vụ đúng MIME
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" \
  http://localhost/moodle/theme/ptedu/fonts/lora-400-vietnamese.woff2   # -> 200 font/woff2

# Truy cập ẩn danh dẫn về portal login
curl -s -L -o /dev/null -w "%{url_effective}\n" \
  http://localhost/moodle/local/quizportal/index.php
```

Cần tài khoản học viên thật, **chưa kiểm chứng tự động**:

1. **(A)** `UPDATE mdl_user SET confirmed = 0 WHERE username = '...'` → đăng nhập
   phải bị từ chối; đặt lại `1` → vào được.
2. **(C)** Tick "Ghi nhớ đăng nhập" → đăng xuất → ô tên đăng nhập điền sẵn và ô
   tick đã bật. Bỏ tick, lặp lại → ô trống.
3. **(D)** Chưa đăng nhập, mở thẳng `mod/quiz/view.php?id=<cmid>` → sau khi đăng
   nhập phải về **đúng trang quiz đó**, không về danh sách đề.
4. **(E)** DevTools → Network lọc `font`: file tải từ `/theme/ptedu/fonts/`,
   **không** có request nào ra `fonts.gstatic.com`. Computed style của
   `.quizportal-login__quote` phải là `Lora`.
5. **Dashboard không hồi quy:** học viên có ≥1 đề đã làm và ≥1 đề đang mở → cả 4
   trạng thái hiển thị đúng, thanh thống kê đúng, 4 tab lọc còn chạy.
6. **Responsive:** xem cả hai trang ở ~390px — `styles.css` có breakpoint 780px
   (login) và 700px (dashboard).
