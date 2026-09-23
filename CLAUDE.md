# PTEducation — Trạng thái dự án

> **Đây là file vào cửa.** Phiên mới đọc file này là đủ để tiếp tục làm việc,
> không cần duyệt lại toàn bộ dự án. **Mọi phiên phải cập nhật file này trước
> khi kết thúc** — việc đã làm, việc dang dở, việc định làm.

Cập nhật lần cuối: **2026-09-23**

---

## 1. Dự án là gì

Moodle 4.5 (`MOODLE_405_STABLE`) chạy trên WAMP ở `http://localhost/moodle`,
được tuỳ biến thành **cổng luyện thi TOEIC của PTEducation**.

Hai mục tiêu:
1. **Học viên** — trang luyện đề TOEIC/ETS có âm thanh, hình ảnh, giao diện đẹp.
2. **Quản trị** — quản lý khoá học / học viên / đề thi minh bạch, ít nhảy trang.

## 2. ⚠️ Môi trường — đọc trước khi chạy lệnh

| | |
|---|---|
| **PHP của Apache là `8.1.33`** | `/c/wamp64/bin/php/php8.1.33/php.exe`. **Không dùng `php8.5.0`** — WAMP có 7 bản PHP, bản mới nhất là 8.5.0 nhưng Moodle 4.5 từ chối nó ("PHP version 8.4 and higher are not supported") và mọi lệnh CLI sẽ fail |
| **CLI cần thêm cờ** | `php.exe -d max_input_vars=5000 admin/cli/<script>.php` — thiếu cờ này thì upgrade/purge bị chặn |
| `php` không có trong PATH của bash | Luôn gọi bằng đường dẫn đầy đủ |
| Sau khi sửa SCSS | **Bắt buộc** `admin/cli/purge_caches.php`, nếu không CSS cũ vẫn được phục vụ |
| Sau khi thêm class mới | **Bắt buộc** `purge_caches` trước khi chạy, nếu không autoload báo "Class not found" |
| **Heredoc trong Bash nuốt backslash** | Kể cả `cat > f.php <<'EOF'` (đã quote) vẫn ăn mất một lớp backslash. Đã dính 3 lần: `rtrim($d, '/\\')` thành chuỗi hỏng, `extends \\theme_boost\\output\\core_renderer` thành ký tự tab. **File nào có backslash — PHP namespace, regex, đường dẫn Windows — thì dùng công cụ Write, đừng dùng heredoc.** Python script qua heredoc cũng dính y hệt |

## 3. Đã làm xong

### Track 0 — Theme & hệ thống thiết kế ✅ (2026-09-18)

`theme/ptedu` — **child theme của Boost**, đã đăng ký và **đang là theme active**.

- `config.php` — `$THEME->parents = ['boost']`. **Cố ý không định nghĩa lại
  `$THEME->layouts`** → thừa hưởng layout của Boost, tự đồng bộ khi core nâng cấp.
  **Cố ý không đặt `precompiledcsscallback`** — nó trả về `theme/boost/style/moodle.css`
  dựng sẵn, sẽ bỏ qua toàn bộ SCSS và khiến bảng màu thành no-op một cách âm thầm.
- `scss/pre.scss` — design token + ghi đè biến Bootstrap. Chạy **trước** preset của
  Boost, mà mọi biến trong preset đều có `!default` → ghi đè thắng **toàn site, gồm cả trang admin**.
- `scss/fontface.scss` — 18 khối `@font-face`, font tự host ở `theme/ptedu/fonts/`.
- `scss/post.scss` — bộ component + mirror token ra `:root` dạng `--pt-*` để CSS
  thuần của plugin dùng lại được.
- **Gotcha đã xử lý:** `core_scss` chỉ đăng ký import path qua `set_file()`, mà
  hàm đó **không bao giờ được gọi** khi theme cấp `$THEME->scss` dạng closure trả
  string. → `@import` trong post.scss sẽ fail âm thầm. Phải **nối file bằng PHP**
  trong `theme_ptedu_get_main_scss_content()`.
- **`!important`:** `local/quizportal/styles.css` trước đây có 7 khai báo, giờ
  **còn 0**. Nhưng cap `.login-container` **không** gỡ được nếu thiếu `!important`:
  Boost đặt `width: 500px !important` ở `theme/boost/scss/moodle/login.scss:81`,
  mà `!important` chỉ bị `!important` khác thắng. Theme giữ **đúng một** khai báo
  `!important` cho việc này, có ghi chú tại chỗ. *(Ghi chú phiên trước nói đã bỏ
  hết `!important` là sai — plugin thì hết, theme thì cần 1.)*

**Bảng màu "Giấy & Mực"** — đã kiểm tra WCAG, **không giá trị nào dưới AA**:

| Token | Hex | Vai trò | Tương phản |
|---|---|---|---|
| `--pt-paper` | `#f7f5f0` | nền giấy ấm | — |
| `--pt-sheet` | `#fffdf9` | khối nổi | — |
| `--pt-ink` | `#14303a` | chữ chính, `$primary` | 12.73 AAA |
| `--pt-ink-soft` | `#46626e` | chữ phụ | 5.96 AA |
| `--pt-ink-faint` | `#5f727c` | nhãn nhỏ | 4.61 AA |
| `--pt-line` | `#ded8cd` | viền ấm | hairline |
| `--pt-accent` | `#a75d1b` | **điểm nhấn duy nhất**, `$link-color` | 4.56 AA · trắng trên nền 4.97 AA |
| `--pt-correct` | `#2e7d52` | **dành riêng: câu đúng** | 4.62 AA |
| `--pt-wrong` | `#b3261e` | **dành riêng: câu sai** | 6.00 AA |

> **Luật màu — đừng phá:** `--pt-correct` và `--pt-wrong` **không bao giờ** dùng
> cho khung sườn giao diện. Trong ứng dụng thi, xanh lá = đúng và đỏ = sai là
> ngữ nghĩa bắt buộc; thiết kế cũ tiêu đỏ cho nút bấm nên tới trang xem kết quả
> là hết màu để đánh dấu câu sai. Đó là lý do chính phải đổi bảng màu.
>
> `$primary` = ink (không phải accent) vì Moodle đổ rất nhiều `.btn-primary` ra
> màn hình. Link mới lấy accent — link màu ink sẽ trùng chữ thường, mất affordance.

### Trang chủ site ✅ (2026-09-18)

- `theme/ptedu/classes/output/core_renderer.php` — **phải kế thừa
  `\theme_boost\output\core_renderer`, không phải `\core_renderer`.** Kế thừa
  thẳng từ core sẽ mất các override của Boost (`firstview_fakeblocks()`…) mà
  layout `drawers` có gọi → trang chủ fatal ngay.
- Override `course_content_footer()` để chèn nút "Đăng nhập" lớn ở cuối nội dung
  trang chủ (chỉ khi chưa đăng nhập). Chọn hook này vì `theme_boost/drawers`
  render nó **bên trong `#topofscroll`** — đây là điểm chèn duy nhất core cho
  giữa nội dung và footer. Hàm cha trả `''` trên site course (short-circuit ở
  `SITEID`) nên không đè mất gì.
- **Hệ quả cần biết:** nút nằm **trong** panel trắng, không phải nổi bên dưới.
  Muốn nút tách hẳn ra ngoài panel thì phải override cả layout `frontpage` +
  vendor ~180 dòng `drawers.mustache` — đánh đổi drift khi core nâng cấp.
- CSS canh giữa: `.pagelayout-frontpage #page.drawers` thành flex column,
  `#topofscroll` dùng `margin-top/bottom: auto` (phải ép `flex: 0 0 auto` vì
  `.main-inner` của Boost mang sẵn `flex: 1 0 auto` sẽ kéo giãn panel).

### Cổng học viên `local/quizportal` ✅ 2 màn hình

Chi tiết: **`local/quizportal/PROGRESS.md`**.

- Trang đăng nhập + dashboard danh sách bài thi, **đã nối dữ liệu Moodle thật**.
- **Bố cục trang đăng nhập (2026-09-18): 2 dòng trên/dưới**, không còn 2 cột.
  Dòng trên = dải nhận diện (logo + tên trung tâm), dòng dưới = form. Bản 2 cột
  chia 40/60 trong khung 500px làm cột form chỉ còn ~200px. Đã bỏ pull quote,
  danh sách `KỲ THI` / `LỚP ĐANG MỞ` và bảng `ĐÁP ÁN MẪU`; giữ lại ẩn dụ vé thi
  bằng hai lỗ đục tròn ở đường nối giữa hai dòng. `max-width` nay là 520px.
- Gắn vào site qua `$CFG->alternateloginurl` (`config.php:24`) — **tham chiếu duy
  nhất tới plugin từ bên ngoài**.
- Rà soát 2026-09-18 đã vá 7 lỗi (A–G), gồm 2 lỗi bảo mật. Đáng nhớ nhất:
  **`\core\session\manager::validate_login_token()` trả `true` ngay lập tức khi
  `$CFG->alternateloginurl` được đặt** (`lib/classes/session/manager.php:1288`)
  → phép kiểm tra token của core là no-op ở đây. Cổng tự quản token trong `$SESSION`.

### B1 — Trình nhập đề TOEIC ✅ xong (2026-09-18 → 2026-09-21)

**Đã xong: hợp đồng dữ liệu + trình kiểm tra.**

- `classes/local/import/spec.php` — **nguồn sự thật duy nhất** về định dạng. File
  mẫu, file demo và trình kiểm tra đều sinh từ đây nên không thể lệch nhau.
- `classes/local/import/template_writer.php` — sinh `.xlsx` 4 sheet
  (`meta`, `passages`, `questions`, `huong-dan`). Help của từng cột gắn làm
  comment trên ô tiêu đề.
- `classes/local/import/workbook_reader.php` — đọc + kiểm tra. Gom **mọi lỗi
  cùng lúc** kèm sheet/dòng, không dừng ở lỗi đầu tiên.
- `cli/make_template.php`, `cli/validate_workbook.php` — chạy được không cần UI.
- `samples/` — file mẫu đã sinh sẵn + `HUONG-DAN-NHAP-DE.md` (hướng dẫn số hoá
  từ PDF, dành cho người nhập liệu).

**Quyết định đã chốt (đừng bàn lại):**
- `.xlsx` chứ không CSV: nhiều sheet trong một file, không dính lỗi encoding /
  delimiter với tiếng Việt. PhpSpreadsheet có sẵn và **đã được autoload**
  (`lib/classes/component.php:148`), cả reader lẫn writer.
- **Một file mp3 cho cả Listening**, định vị từng câu bằng cột `audio_start`
  (mm:ss). Chỉ cần mốc bắt đầu — mốc kết thúc suy ra từ nhóm kế tiếp.
- **Ngữ liệu chung để ở sheet `passages` riêng**, câu hỏi trỏ tới bằng mã. Part
  3/4 = transcript, Part 6/7 = đoạn văn.
- Part 1 và 2 **không in phương án** (đúng như đề thật) — chỉ có `answer` và
  `transcript`.
- **Bẫy đã xử lý:** Excel tự đổi `0:35` thành số thời gian (phân số của một
  ngày). `workbook_reader::normalise_timecode()` nhận cả hai dạng; template khoá
  cột `audio_start` thành Text.
- **Mốc `audio_start` lưu ở bảng riêng của plugin** (chủ đề chốt 2026-09-18).
  Không dùng `qbank_customfields` cũng không nhúng vào đề bài: gọn hơn, truy vấn
  nhanh hơn, và không đụng vào cấu trúc câu hỏi của core.
- **Trình nhập đặt ở trang quản trị của plugin**, có ô chọn khoá học đích khi
  upload — không nhúng vào từng khoá học.

**Quyết định về cách thi (chốt 2026-09-18):**
- **Cưỡng chế Listening một chiều** — không cho tua lại, không cho quay về câu
  trước. Thực thi ở trang làm bài riêng (A6), vì `navmethod` là cột trên bảng
  `quiz` còn `quiz_sections` không có cột điều hướng → Moodle **không thể** để
  Listening khoá mà Reading tự do trong cùng một quiz.
- **Có trang cầu nối giữa hai phần.** Hết Listening → một trang thông báo phần
  nghe đã kết thúc, giới thiệu phần Reading, có nút "Bắt đầu" để vào. Đây cũng
  là ranh giới kỹ thuật: qua trang này thì khoá một chiều được gỡ, Reading cho
  đi lại tự do giữa các câu.

**`xml_builder.php` ✅ xong 2026-09-21.** Nhận mảng của `workbook_reader`, trả
Moodle XML. `build()` dựng trong RAM, `build_to_file()` ghi thẳng ra đĩa theo
từng câu (chỉ giữ media của một câu trong bộ nhớ). `plan()` là hàm thuần trả thứ
tự slot — **importer phải gọi lại chính hàm này** để xếp slot và ranh giới Part,
không được tự suy ra thứ tự lần thứ hai.

**Luật sống còn đã cài vào builder — đừng phá:**

| Dữ liệu | Khi đang thi | Sau khi nộp |
|---|---|---|
| Lời thoại Part 1/2 (cột `transcript`) | ẩn | `<generalfeedback>` |
| Lời thoại Part 3/4 (`passages.content`) | **ẩn** — khối ngữ liệu chỉ mang dòng directions + ảnh | `<generalfeedback>` của **từng câu** trong nhóm (lặp 3 lần, chấp nhận) |
| Bài đọc Part 6/7 (`passages.content`) | hiện đầy đủ | — |

> Đưa `content` của Part 3/4 vào khối hiển thị là **hỏng cả phần nghe**: thí sinh
> đọc script thay vì nghe, và lỗi này không báo gì cả. Đây là lý do `passage_xml()`
> kiểm tra `spec::LISTENING_PARTS` trước khi in `content`.

**Bẫy của core đã kiểm chứng và né được:**
- `qtype_multichoice` **bỏ âm thầm** đáp án có text rỗng, và dưới 2 đáp án là lỗi
  cứng `notenoughanswers` (`question/type/multichoice/questiontype.php:149`).
  Part 1/2 trong đề thật **trống cả 4 cột phương án** → builder in nhãn `(A) (B)
  (C) (D)` kèm `answernumbering=none`. Giống đề thật, và không bao giờ ra câu hỏi
  thiếu ô chọn.
- `defaultquestion()` mặc định `penalty = 0.3333333` (`question/format.php:779`)
  → phải ghi `<penalty>0</penalty>`, nếu không TOEIC bị trừ điểm khi sai.
- `questiontextformat` mặc định là FORMAT_MOODLE → phải khai `format="html"`.
- `idnumber` trùng trong cùng category bị **bỏ âm thầm** (`question/format.php:453`)
  → mã sinh theo `{slug-tên-đề}-q007`, importer phải tạo category riêng cho mỗi đề.
- `<file>` phải là **con của `<questiontext>`**, anh em với `<text>`; attribute là
  `name` + `path="/"` (`import_files_as_draft`).
- Tag khối ngữ liệu là `ngulieu-partN`, **cố ý không phải `partN`**: dashboard
  đếm câu theo `/^part([1-7])$/` (`dashboard_repository.php:187`), tag giống nhau
  sẽ báo Part 3 có 52 câu thay vì 39.

**Đã kiểm chứng trên đề thật `DE_01`** (không ghi gì vào database): 242 slot
(200 câu + 42 ngữ liệu), XML 6,22 MB, dựng hết 0,03 s, đỉnh RAM 28 MB. Đọc lại
bằng chính `xmlize()` của core theo đúng đường dẫn `qformat_xml` tra cứu: đủ 242
node, 12 ảnh base64 giải mã **khớp byte** với file gốc, idnumber và tên đều duy
nhất, mỗi câu đúng một `fraction="100"` khớp cột `answer`, Part chạy tăng dần
không đan xen. File nghe 44 MB **không** bị nhúng vào XML.

**`importer.php` ✅ xong 2026-09-21. Đã chạy thật trên `DE_01`.** Kèm theo:
`db/install.xml` + `db/upgrade.php` (bảng `local_quizportal_slotmeta`),
`db/events.php` + `classes/observer.php` (dọn bảng khi xoá quiz),
`cli/import_test.php`, và `spec::PART_NAMES` / `spec::part_heading()`.
Cả hai đường vào đều đã chạy thật: `import_folder()` với `DE_01`, và
`import_archive()` với một file `.zip` bọc trong thư mục con (kiểu Windows nén).

Kết quả thật đo được: **242 slot trong 3,5 giây** (200 câu + 42 ngữ liệu), 7
section, 200 tag `partN`, 12 ảnh khớp từng byte, `Test_01.mp3` 42,1 MB lưu riêng.
~~Đề nằm ở quiz `#3` / cmid `4`~~ — **đã thay (phiên 7): nay là quiz `#9` / cmid
`10` trong khoá `#2`, đang ẩn**, nhập từ `DE_01.xlsx` đã sửa phần nghe.

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`install.xml` chỉ chạy khi cài LẦN ĐẦU.** Plugin đã cài từ trước nên thêm
  bảng bắt buộc phải có `db/upgrade.php`. Upgrade vẫn báo "Thành công" mà không
  tạo bảng gì — im lặng hoàn toàn. Mất một vòng mới phát hiện.
- **Driver CSDL trả cột số về dạng CHUỖI.** `$record->slot !== 1` luôn đúng vì
  `'1' !== 1`. Mọi so sánh `!==` với cột DB phải ép `(int)` trước.
- **Không bao giờ đoán file `.xlsx` nào.** Thư mục `De_1` có cả `DE_01.xlsx` và
  `DE_01.truoc-khi-dien.xlsx`; lấy "file đầu tiên" thì `scandir` trả bản nháp 41
  câu và nhập nhầm mà không báo gì. Nay gặp >1 file là báo lỗi, có `--workbook`.
- **Rollback sạch tuyệt đối** — đã kiểm chứng bằng lần chạy hỏng: quiz, câu hỏi,
  category, file đều không để lại rác. Toàn bộ khâu ghi nằm trong một transaction.
- **Core không biết bảng của plugin.** Xoá quiz thì core dọn slot, section, file
  trong context — nhưng `local_quizportal_slotmeta` còn nguyên 18 dòng mồ côi (bắt
  được khi thử đường zip). Đã thêm observer `course_module_deleted`, kiểm chứng
  xoá xong còn 0 dòng. **Bảng mới nào của plugin cũng cần đường dọn tương tự.**

**Quyết định trong importer:**
- Phân trang **theo nhóm**: khối ngữ liệu mở trang mới và giữ câu của nó cùng
  trang; câu đứng một mình (Part 1, 2, 5) mỗi câu một trang → 103 trang. Cần thế
  vì tiêu đề Part chỉ bắt đầu được ở đầu trang, và đọc bài Part 7 ở trang này rồi
  trả lời ở trang khác thì không dùng được.
- `questionsperpage = 0` để `quiz_add_quiz_question()` khỏi tự phân trang trước.
  **Cảnh báo (đã đính chính 2026-09-21):** lưu form cài đặt đề **không** tự dồn
  trang. `quiz_update_instance()` chỉ gọi `quiz_repaginate_questions()` khi ô
  *Repaginate now* được tick (`mod/quiz/lib.php:173`) — nhưng JS của form **tự
  tick** ô đó ngay khi đổi ô *New page*. Nút *Repaginate* ở trang sửa câu hỏi và
  ô *Shuffle* của section cũng phá cấu trúc. Trang kết quả nhập đề có cảnh báo cả 4.
- Review options: khi đang thi **chỉ** thấy câu trả lời của chính mình. Bật
  `generalfeedback` lúc đang thi là lộ toàn bộ lời thoại.
- Mỗi đề một question category riêng — `idnumber` chỉ cần duy nhất trong một
  category, dùng chung là mất handle âm thầm.

**Trang `import.php` ✅ xong 2026-09-21.** Quản trị › Khoá học › **Nhập đề TOEIC**.
Upload một `.zip`, chọn khoá học, mục, tên đề (tuỳ chọn), ẩn/hiện → gọi đúng
`importer::import_archive()` như CLI. Kèm: `classes/form/import_form.php`,
`classes/output/import_report.php` + `templates/import_report.mustache`,
`db/access.php`, `settings.php`, CSS `.quizportal-import*` (plugin version
**2026092103**, đã chạy upgrade). File mẫu trống / có dữ liệu tải thẳng từ trang
(`?download=blank|demo`), sinh từ `spec` mỗi lần nên không lệch trình kiểm tra.

**Quyết định trong trang nhập (mặc định an toàn, đổi được):**
- Capability `local/quizportal:importtests` ở **mức khoá học**, mặc định **chỉ
  manager**. Muốn giáo viên tự nhập đề thì override role, không cần sửa code —
  nhưng giáo viên không vào được trang admin, sẽ cần thêm lối vào từ khoá học.
- Có capability của plugin **chưa đủ**: form còn đòi `moodle/course:manageactivities`,
  `mod/quiz:addinstance`, `moodle/question:add` ở khoá đích
  (`import_form::REQUIRED_CAPABILITIES`), vì `add_moduleinfo()` và `qformat_xml`
  **không tự kiểm tra quyền** — trang của core kiểm tra trước khi gọi chúng.
- Ô "Ẩn đề với học viên" **mặc định bật** (CLI thì mặc định hiện).
- Nhập xong: xoá file nháp + chuyển hướng sang `?done=cmid` (kết quả lưu ở
  `$SESSION`). F5 hay bấm lại **không** nhập đề lần hai — đã kiểm chứng.
- Trang kết quả cảnh báo khi thiếu `audio_start` (trình kiểm tra không cảnh báo).

**`lib.php` — phục vụ file nghe ✅ xong 2026-09-21. B1 HOÀN TẤT.**
`local_quizportal_pluginfile()` + `classes/local/listening.php`
(`get_file()`, `get_url()`, `can_listen()`). URL:
`/pluginfile.php/{ctx quiz}/local_quizportal/listening/{quizid}/{file}` — **trang
làm bài (A6) lấy URL bằng `listening::get_url()`, đừng tự ghép.**

**Ai được nghe (quyết định 2026-09-21, đổi được):**
- Giáo viên/admin (`mod/quiz:preview` hoặc `mod/quiz:viewreports`): luôn nghe được.
- Học viên: **chỉ khi đang có lượt làm `inprogress`**. Chưa làm bài → không nghe
  được (file nghe chính là đề — có trước là nghe tuỳ ý rồi chia sẻ).
- Sau khi nộp: nghe lại được **khi và chỉ khi** Review options của quiz đang cho
  xem *General feedback* — vì lời thoại nằm ở đó, nghe lại không lộ gì thêm.
  Không có công tắc riêng: admin tắt General feedback là tắt luôn nghe lại.
- Bị từ chối thì trả **404**, không lộ đề có file nghe hay không.
- `send_file()` tự mở khoá session trước khi stream (không treo autosave của bài
  thi) và hỗ trợ Range (tua được). Lifetime 0 như `quiz_pluginfile()`.
- **Chưa chặn được "phát một lần"** — đó là việc của trang làm bài (A2), và chỉ
  chặn được phía trình duyệt: ai đang làm bài vẫn tải được file.

**Còn treo sau B1:**
1. ~~Chưa ai bấm thử `import.php` bằng trình duyệt~~ — **đã thử 2026-09-22**:
   người dùng nhập `DE_01` vào khoá `#10` qua trang này (quiz `#17`, cmid 26,
   đang **hiện**; log `course_module_created` có `origin=web`, 242 câu + 242 tag).
2. ~~Sao lưu / khôi phục / nhân bản đề làm mất file nghe và `slotmeta`~~ — **đã vá
   2026-09-22** (mục "Sao lưu / khôi phục đề" bên dưới). *Đính chính ghi chú cũ:*
   đề **không có dòng `slotmeta` nào** thì `paper::is_toeic()` = false → router
   **không** chặn → học viên làm nó bằng trang quiz của core, như quiz thường,
   không có âm thanh. Trang làm bài chỉ báo "lỗi cấu trúc" khi `slotmeta` có nhưng
   lệch với đề. Bản sao lưu tạo **trước** 2026-09-22 rơi vào trường hợp thứ nhất.
3. ~~Xoá cả khoá học để lại `slotmeta` mồ côi~~ — **đã vá 2026-09-22** (xem A6).

### A6 + A2 — Trang làm bài + audio phát một lần ✅ (2026-09-22)

Học viên bấm "Bắt đầu" ở trang quiz của core như cũ → core tạo lượt làm rồi
chuyển tới `mod/quiz/attempt.php` → **plugin chặn và chuyển sang
`local/quizportal/attempt.php`**. Chỉ áp dụng cho đề có `slotmeta` (đề nhập bằng
B1); quiz thường (`#1 TEST`) vẫn chạy trang của core.

| File | Vai trò |
|---|---|
| `attempt.php` | Trang làm bài, thay `mod/quiz/attempt.php` + `summary.php` |
| `process.php` | Nhận form (`endlistening`, `startreading`, `finish`, `timeup`), thay `processattempt.php` |
| `ajax.php` | `listen` (bắt đầu đồng hồ nghe), `autosave`, thay `autosave.ajax.php` |
| `classes/local/exam/router.php` + `db/hooks.php` | Chặn 4 script core với đề TOEIC |
| `classes/local/exam/paper.php` | Cấu trúc đề: nhóm câu, mốc audio, **kiểm tra đề còn khớp lúc nhập** |
| `classes/local/exam/attempt_state.php` | Bảng mới `local_quizportal_attemptstate`: mốc bắt đầu/kết thúc nghe, mở phần đọc |
| `classes/local/exam/exam_session.php` | Kiểm tra quyền chung + **lọc slot được ghi theo phần đang làm** |
| `classes/output/exam_page.php`, `templates/exam_*.mustache` (5 file) | Giao diện |
| `amd/src/exam.js` | Phát audio, tự chuyển câu, autosave, đồng hồ, phiếu trả lời |
| `styles.css` phần `.quizportal-exam*` | ~590 dòng (từ dòng 643), vẫn **0 `!important`** |

Plugin version **2026092200** (đã chạy upgrade, bảng đã tạo — đã kiểm tra).

**Cách phần nghe chạy (người dùng chọn "liền mạch như thi thật", 2026-09-22):**
- Cả phần nghe là **một trang**, chứa đủ 123 slot. Nhóm câu trên màn hình đi theo
  âm thanh: tới mốc `audio_start` của nhóm nào thì hiện nhóm đó. Trước câu 1 hiện
  hướng dẫn Part 1. Học viên không có nút dừng/tua (giáo viên xem thử thì có).
- **Âm thanh chạy theo đồng hồ server**: bấm "Bắt đầu" lần đầu thì server ghi
  `listenstart`; vị trí luôn là `bây giờ − listenstart`. Tải lại trang, đóng tab,
  mất mạng → quay lại là nghe tiếp **đúng chỗ băng đang chạy**, đoạn đã lỡ mất
  luôn. Tạm dừng (phím media, tai nghe) → hiện bảng "Âm thanh đã dừng", bấm tiếp
  thì nhảy tới vị trí hiện tại. Âm thanh lệch đồng hồ quá 5 giây (mạng chậm, tua
  bằng devtools) → tự kéo về.
- Hết băng → tự nộp phần nghe → **trang chuyển tiếp** (đếm số câu nghe đã làm,
  giới thiệu Part 5–7) → nút "Bắt đầu phần đọc". Học viên bỏ đi giữa chừng thì
  server tự đóng phần nghe khi quá `listenstart + độ dài + 90 giây`.
- Phần đọc cũng là một trang (119 slot), hiện một nhóm mỗi lần, đi lại tự do:
  nút Trước/Tiếp, phím ←/→, **phiếu trả lời** 101–200 bấm để nhảy câu, nhớ nhóm
  đang xem khi tải lại. Part 6/7: bài đọc bên trái cuộn riêng, câu hỏi bên phải.
  Nút "Nộp bài" luôn nằm trên thanh trên cùng → hộp xác nhận liệt kê câu chưa
  làm → trang kết quả của plugin (A7, bên dưới).
- Autosave 1,5 giây sau mỗi lần chọn, mỗi 30 giây, khi chuyển nhóm, và gửi
  `sendBeacon` khi rời trang. Đồng hồ đếm ngược riêng; về 0 thì gửi `timeup`,
  core tự quyết định có thật hết giờ không.

**Luật cưỡng chế ở server — đừng phá:**
- `exam_session::restrict_submission()` **ghi đè trường `slots` của request**
  trước khi question engine đọc nó: chỉ slot của phần đang làm được xử lý. Qua
  trang chuyển tiếp là câu nghe **đóng băng** — đã thử gửi lén đáp án câu 1 lúc
  ở trang chuyển tiếp và lúc ở phần đọc, DB không đổi. Thiếu trường `slots` thì
  engine xử lý **mọi** slot, nên hàm luôn đặt nó, kể cả rỗng.
- Học viên báo "hết băng" sớm hơn `listenstart + độ dài − 15 s` → bị từ chối.
  Chỉ lượt xem thử của giáo viên mới "Bỏ qua phần nghe" được.
- `mod/quiz/autosave.ajax.php` với đề TOEIC → **từ chối** (không ai trên trang
  của plugin gọi nó, request tới đó là request tự chế).
- Đề bị sửa sau khi nhập (thêm/xoá/đổi chỗ câu, thiếu hoặc sai thứ tự mốc, mất
  file nghe) → trang báo "Đề đang có lỗi cấu trúc" kèm lý do, **không chạy**. Thà
  không thi còn hơn phát âm thanh lệch câu. So theo *question bank entry*, không
  theo question id — sửa lỗi chính tả một câu (tạo version mới) không làm hỏng đề.

**Giới hạn đã biết (chấp nhận, ghi lại để khỏi bất ngờ):**
- Khoá "không quay lại câu trước" **trong** phần nghe chỉ ở giao diện (nhóm đã qua
  bị ẩn). Request tự chế vẫn sửa được câu nghe trước đó *khi phần nghe còn chạy*.
- Lúc băng đọc hướng dẫn Part 2/3/4, màn hình vẫn giữ nhóm cuối của Part trước
  (không có mốc cho đoạn hướng dẫn). Muốn khác thì thêm mốc bắt đầu Part vào
  `audio_marks.py` + `slotmeta`.
- App di động / web service đi qua API của `mod_quiz`, **không** qua router.
- Chưa thử Safari/Firefox (chính sách tự phát âm thanh khác Chrome).

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`require_login()` bỏ qua callback `after_require_login` với site admin**
  (`lib/moodlelib.php:2426`) → router dùng hook `after_config`, nếu không admin xem
  thử đề sẽ rơi vào trang core.
- **Moodle luôn nạp `amd/build/*.min.js`**, không bao giờ nạp `amd/src`. Máy không
  có grunt → `amd/build/exam.min.js` là **bản sao y nguyên** của `amd/src/exam.js`.
  Sửa JS xong phải `cp` sang build rồi `purge_caches`.
- **Xoá cả khoá học KHÔNG bắn `course_module_deleted`** — `remove_course_contents()`
  gọi thẳng `quiz_delete_instance()` (`lib/moodlelib.php:4853`), chỉ bắn một
  `course_content_deleted` ở cuối. Observer của B1 vì thế để lại 242 dòng
  `slotmeta` mồ côi mỗi lần xoá khoá. Đã thêm observer quét theo `quizid` không
  còn tồn tại. **Xoá lượt xem thử cũng không bắn `attempt_deleted`** → quét lúc
  tạo state mới.
- `delete_course()` tự lưu **bản sao cả khoá vào thùng rác danh mục** (site bật
  `categorybinenable`). Dọn dữ liệu thử phải dọn cả ở đó.
- **Core không tìm favicon ở theme cha** (`theme_config.php:1872`) → mọi trang của
  theme `ptedu` trả 404 favicon từ trước tới nay. Đã chép `theme/boost/pix/favicon.ico`
  sang `theme/ptedu/pix/`.
- Bộ lọc URL của Moodle biến địa chỉ web trong bài đọc Part 7 thành **link bấm
  được** → một cú bấm lỡ là rời phòng thi. JS đổi link trong `.qtext` thành chữ.
- "Clear my choice" của core không có bản dịch tiếng Việt → JS đổi thành "Bỏ chọn".
- Test CLI: tạo lượt làm rồi mở trang trong **cùng một** process CLI → "theme has
  already been set up" → phải tạo `moodle_page` mới giữa hai bước.
- Heredoc lại nuốt một lần append CSS (lần này vì dấu `'` trong tên font).

**Đã kiểm chứng** (khoá thử riêng + 2 tài khoản thử, dựng và xoá bằng
`tools/exam_e2e/fixture.php`; đề `#9` và học viên thật không bị đụng tới):
- Qua HTTP (curl): chặn đủ 4 script core, nộp sớm bị từ chối, câu nghe đóng băng
  ở trang chuyển tiếp và phần đọc, nộp bài → review, chấm khớp đáp án; giáo viên
  bỏ qua phần nghe và được điểm 2/2 khi chọn đúng.
- Trong Chrome thật (`tools/exam_e2e/`): **41/41**, 4 lần chạy cuối đều đạt (2 lần
  trên code cuối cùng, 1 lần theo đúng các bước trong README) — âm
  thanh phát sau cú bấm, nghe tiếp đúng vị trí đồng hồ sau khi tải lại, bảng tạm
  dừng, tua lén bị kéo về, câu 1 hiện đúng lúc 1:38, Part 3 không lộ lời thoại,
  hết băng tự sang trang chuyển tiếp, phiếu trả lời, phím mũi tên, giữ nhóm khi
  tải lại, không tràn ngang ở 390px, không lỗi JS, không request 4xx.
- 5 kiểu đề hỏng đều bị phát hiện, đúng lý do.
- Sau khi xoá dữ liệu thử: `slotmeta` chỉ còn 242 dòng của đề `#9`,
  `attemptstate` 0 dòng, thùng rác danh mục trống.

### A7 + A3 — Trang kết quả + quy đổi điểm 10–990 ✅ (2026-09-22)

Mọi đường tới `mod/quiz/review.php` của một đề TOEIC (nộp bài xong, nút "Xem
lại" ở trang quiz, báo cáo của giáo viên) được router chuyển sang
`local/quizportal/review.php`. Thêm `&classic=1` vào link `mod/quiz/review.php`
để tới trang review của core (trang kết quả có link này cho giáo viên).

| File | Vai trò |
|---|---|
| `classes/local/exam/score_scale.php` | **A3**: bảng quy đổi 0–100 câu đúng → 5–495, đọc/lưu/kiểm tra |
| `classes/local/exam/result.php` | Chấm một lượt làm: đúng/sai/bỏ trống từng câu, theo Part, theo phần, điểm. `for_attempts()` chấm hàng loạt bằng **một** câu SQL (dashboard) |
| `review.php` + `classes/output/result_page.php` | Trang kết quả; phân quyền **y hệt** `mod/quiz/review.php` |
| `templates/result_page.mustache`, `result_group.mustache`, `amd/src/result.js` | Giao diện |
| `scale.php` + `templates/scale_editor.mustache` | Quản trị › Khoá học › **Bảng quy đổi điểm TOEIC** (chỉ site admin) |
| `dashboard_repository.php` | Đề TOEIC hiện "ĐIỂM TOEIC 700 / 990" (lượt cao nhất), link thẳng tới trang kết quả |

Không đổi CSDL (bảng quy đổi lưu ở config plugin) → không bump version.

**Bảng quy đổi (người dùng chốt 2026-09-22: Oxford, sửa được, hiện một con số):**
- ETS **không công bố** bảng quy đổi; mỗi đề thật có bảng riêng. Sách ETS 1000 LC
  của đề 1 cũng không kèm bảng (đã lật cả 296 trang script). Mặc định dùng bảng
  của **Oxford English Testing** (link ở `score_scale.php` và trên `scale.php`).
- Oxford in **theo khoảng** (96–100 câu → 470–495, giống nhau cho Nghe và Đọc).
  Một con số = đường qua điểm giữa mỗi khoảng, `5c − 7,5` làm tròn nửa-lên theo
  bước 5, **tức đúng bằng `5c − 5`**, kẹp 5–495. Đã kiểm bằng máy: cả 101 giá trị
  nằm trong khoảng Oxford in; ví dụ in dưới bảng Oxford (79 + 63 câu → 665–715)
  ra 390 + 310 = **700**.
- Admin sửa từng ô ở `scale.php`: 5–495, bước 5, không giảm dần. Trình duyệt chặn
  sai bước/sai khoảng, server chặn bảng giảm dần. Nguồn bảng hiện dưới mọi điểm.
- **Điểm không lưu sẵn** — tính lại mỗi lần xem từ trạng thái câu hỏi của core.
  Đổi bảng hay regrade là mọi trang (kể cả lượt làm cũ) đổi theo ngay.
- Đề không đủ 100 câu mỗi phần: quy về thang 100 trước khi tra bảng (có ghi chú).
- Sổ điểm Moodle vẫn là điểm thô (`142 / 200`) — không đụng.

**Trang kết quả:**
- Phiếu báo điểm (dùng lại ẩn dụ vé thi của trang đăng nhập): tổng, Nghe, Đọc,
  số câu đúng, lượt thứ mấy, nộp lúc nào, làm bao lâu. Bảng 7 Part.
- Xem lại từng nhóm câu như phần đọc của trang làm bài, bộ lọc **Tất cả / Sai /
  Bỏ trống / Đúng**, phiếu trả lời đã chấm bấm để nhảy câu, link `#cau-37`.
- **Lời thoại Part 3/4 hiện một lần cho cả nhóm** (review của core lặp 3 lần).
  Làm được vì `result_page::split_feedback()` tách general feedback theo hai nhãn
  `xml_builder::LABEL_TRANSCRIPT` / `LABEL_EXPLAIN` (đã đổi sang public). **Đổi hai
  nhãn đó là đề đã nhập hiện feedback nguyên khối**, không hỏng nhưng mất tách.
- "▶ Nghe lại đoạn này": phát từ mốc của nhóm tới mốc nhóm kế tiếp rồi **tự dừng**
  (kiểm chứng: dừng đúng giây 1003). Chỉ hiện khi `listening::can_listen()` cho phép.
- Tôn trọng review options của quiz: điểm chỉ hiện khi marks ≥ MARK_AND_MAX, đúng/
  sai khi correctness bật, đáp án đúng khi rightanswer bật, lời thoại/giải thích
  khi generalfeedback bật.
- **Luật màu:** đây là trang đầu tiên dùng `--correct`/`--wrong` (alias mới trong
  `styles.css`). Không bao giờ chỉ dựa vào màu: câu đúng/sai/bỏ trống khác nhau cả
  **hình dạng** (tô kín / viền / nét đứt) và luôn có chữ ("Đúng · Bạn chọn: B ·
  Đáp án: B", thẻ "Đáp án đúng", "Bạn chọn").

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`delete_user()` âm thầm từ chối xoá site admin đăng nhập thủ công**
  (`lib/moodlelib.php:3612`, chỉ `debugging()`). Test cần admin tạm thì phải gỡ
  quyền trước khi xoá, và kiểm tra lại là đã xoá thật — nếu không sẽ để lại một
  admin có mật khẩu công khai. `fixture.php teardown` làm cả hai.
- **PHP cho phép ký tự Unicode trong tên biến**: `"$correct→$value"` bị đọc là
  biến `$correct→` (rỗng). Dùng `"{$correct}→{$value}"`. IDE bắt được, `php -l` thì không.
- Cổng đăng nhập chuyển site admin tới `admin/index.php`, trang này tải rất lâu
  → test trình duyệt chờ phản hồi đăng nhập, đừng chờ trang tải xong.
- `quiz_prepare_and_start_new_attempt()` với số lượt cố định 1 → lỗi ghi CSDL khi
  người dùng đã có lượt 1 (khoá duy nhất quiz + user + attempt). Tính số lượt kế tiếp.
- Heredoc **lần thứ 5**: Python chạy qua heredoc mất một lớp backslash, một phép
  thay chuỗi có `\n` âm thầm không áp dụng. Script có backslash → viết file bằng
  công cụ Write rồi mới chạy.

**Đã kiểm chứng** (khoá thử + tài khoản thử của `tools/exam_e2e/`, đã xoá hết):
trang làm bài **42/42** (nay 66/66, xem "Cờ đánh dấu"), chấm điểm CLI **22/22**, trang kết quả **27/27**, trang sửa
bảng **14/14**. Sau cùng: `siteadmins` như cũ, bảng quy đổi về Oxford, `slotmeta`
chỉ còn đề `#9`, `attemptstate` rỗng, thùng rác trống. Dashboard của học viên thật
`hocvien1` (quiz thường `#1 TEST`) không đổi.

### Cờ đánh dấu "chưa chắc" ✅ (2026-09-22)

Nút cờ dưới số câu, cả phần nghe lẫn phần đọc. **Người dùng chốt:** phần nghe vẫn
khoá một chiều — cờ ở phần nghe chỉ để **xem lại sau khi nộp**, không mở lại câu.

- **Không có bảng mới, không bump version.** Dùng cột `question_attempts.flagged`
  của core. Core đã render sẵn checkbox cờ trong mỗi câu (nằm trong `.que .info`
  mà CSS của plugin ẩn đi) khi `quiz_get_flag_option()` = EDITABLE. Nút của plugin
  chỉ bật/tắt checkbox đó → form tự lưu mang theo → `update_question_flags()` lưu.
  Hàm này duyệt **mọi** câu có trường cờ trong request, không theo `slots`.
- **Bẫy:** đừng lưu cờ bằng một request AJAX riêng. Checkbox trong form vẫn giữ
  giá trị cũ, và lần tự lưu kế tiếp sẽ **ghi đè ngược** cờ vừa lưu.
- `question/flags.js` của core **không** được nạp trên trang làm bài (plugin không
  gọi `get_html_head_contributions()`). Nếu có ngày nạp nó, nó sẽ thay checkbox bằng
  `input.questionflagvalue` → `setupFlags()` trong `exam.js` không tìm thấy checkbox
  và **ẩn nút** thay vì chạy sai.
- Phần nghe: câu có cờ được tô nền `--accent-tint`. Trang chuyển tiếp liệt kê
  "Bạn đã đánh dấu N câu: 1, 32".
- Phần đọc: góc gấp màu accent trên ô của phiếu trả lời, đếm số câu có cờ, nút
  "Tới câu đánh dấu →" (đi tiếp, hết thì vòng về đầu), hộp nộp bài có dòng "Còn N
  câu đang đánh dấu" + link "Xem lại các câu này".
- Trang kết quả: bộ lọc **Đã đánh dấu**, cờ tô kín cạnh số câu, chữ "Đã đánh dấu
  chưa chắc" ở dòng kết luận, góc gấp trên phiếu trả lời. Hiện bất kể review
  options, giống trang review của core. **Không** tô nền ở trang kết quả: màu
  `--correct` trên nền tint chỉ đạt 4,27:1.
- Tương phản đã tính: trắng/accent 4,97, accent/sheet 4,89, accent/ink **2,79**
  (vì vậy góc gấp có viền màu giấy), accent/tint **4,22** (link trên tint dùng
  accent-dark 5,76), ink-faint/tint **4,26** (link "Bỏ chọn" trong câu có cờ đổi
  sang ink-soft).

### B3 — Bảng điều khiển lớp ✅ (2026-09-22)

**Lớp = một khoá học** (tạm thời, tới khi B2 chốt mô hình lớp). Mỗi ô = **lượt cao
nhất** của học viên ở đề đó — người dùng đồng ý mặc định này, giống dashboard.

| File | Vai trò |
|---|---|
| `classes/local/class_report.php` | Dữ liệu: học viên × đề TOEIC, chấm bằng `result::for_attempts()` (một truy vấn mỗi đề), Part yếu nhất, trung bình lớp |
| `classboard.php` + `classes/output/class_board.php` + `templates/class_board.mustache` | Trang bảng lớp, `?id=COURSE[&group=][&download=excel]` |
| `amd/src/classboard.js` | Sắp xếp cột (bản sao y nguyên ở `amd/build/`) |
| `classlist.php` + `templates/class_list.mustache` | Quản trị › Khoá học › **Các lớp luyện TOEIC** |
| `lib.php` `local_quizportal_extend_navigation_course()` | Link trong menu "Xem thêm" của khoá, chỉ ở khoá có đề TOEIC |
| `db/access.php` | Quyền mới `local/quizportal:viewclassboard` (teacher, editingteacher, manager; RISK_PERSONAL) |

Plugin version **2026092201** (đã chạy upgrade — quyền mới đã có trong CSDL).

- **Học viên** = ghi danh đang hoạt động + có `mod/quiz:attempt` → giáo viên và tài
  khoản bị đình chỉ tự rơi ra. Sắp theo tên hiển thị bằng `core_collator`.
- **Đề** = quiz có `slotmeta`, theo thứ tự trong khoá (`get_cms()`, không phải
  `get_instances_of()` — cái sau xếp theo instance id). Quiz thường không hiện.
- **4 trạng thái ô:** điểm (kèm Nghe/Đọc, số lượt, "đang làm lượt mới") · Đang làm ·
  Bỏ dở · Chưa làm. Phân biệt bằng chữ + hình dạng (nền tint / viền liền / viền đứt).
  **Không** dùng `--correct`/`--wrong` trên trang này.
- **Part yếu nhất** chỉ tính trên các lượt cao nhất (mỗi đề một lượt), không phải
  mọi lượt — làm một đề ba lần sẽ bị tính ba lần, và lần sau đo trí nhớ đáp án.
- Nhóm: dùng `groups_print_course_menu()` + `groups_get_course_group()` của core; chế
  độ nhóm tách biệt mà giáo viên không thuộc nhóm nào → danh sách rỗng.
- Excel: `\core\dataformat::download_data(..., 'excel', ...)`, 16 cột với một đề
  (tên, email, 5 cột mỗi đề, Part yếu nhất, 7 cột % theo Part), số lưu dạng số.

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`$a + $b` với mảng PHP giữ khoá bên TRÁI.** `$data + ['sortvalue' => 700]` khi
  `$data` đã có `sortvalue => ''` → giá trị mới bị bỏ âm thầm, cột điểm không sắp
  xếp được. Test Chrome bắt được. Dùng `array_merge()` khi muốn ghi đè.
- **Ô chọn nhóm của core chỉ tự gửi form với thao tác chuột/bàn phím thật**
  (`accessibleChange`). `page.select()` của puppeteer không kích hoạt → test phải
  tự `form.submit()`.
- **Ảnh `fullPage` của puppeteer vẽ ngăn mục lục khoá học (fixed) đè lên nội dung.**
  Trên màn hình thật không đè (đã đo). Trang có ngăn này → chụp theo khung nhìn.

**Đã kiểm chứng:** trọn bộ 6 bước chạy sạch từ đầu **178/178** (trang làm bài 66,
chấm điểm 22, bảng lớp CLI 27, trang kết quả 27, bảng quy đổi 14, bảng lớp Chrome 22).

### Sao lưu / khôi phục đề ✅ (2026-09-22)

`backup/moodle2/backup_local_quizportal_plugin.class.php` + `restore_local_quizportal_plugin.class.php`.
Mọi đường core chép một quiz đều đi qua đây: **Duplicate**, **thùng rác khoá học**
(đang bật, giữ 7 ngày), sao lưu/khôi phục, sao chép khoá, nhập từ khoá khác.

- Gắn vào `module.xml` (chỗ duy nhất core cho plugin local trong một hoạt động),
  chỉ với module `quiz` có `slotmeta`. Mang theo: các dòng `slotmeta`, file nghe
  (`annotate_files`, item id = quiz id), và — khi có dữ liệu người dùng — `attemptstate`.
- **Bẫy 1: `module.xml` được đọc TRƯỚC khi quiz tồn tại** (bước module tạo course
  module, bước quiz tạo instance sau). Nên lúc đọc chỉ **cất vào thuộc tính của đối
  tượng plugin** (core giữ nguyên đối tượng suốt quá trình khôi phục), rồi ghi ở
  `after_restore_module()` — chạy ở `restore_final_task` bước
  `restore_execute_after_restore`, **trước** `restore_drop_and_clean_temp_stuff`,
  nên ánh xạ id và file tạm vẫn còn. `questionid` ánh xạ qua `'question'`,
  file qua `'quiz'`, lượt làm qua `'quiz_attempt'`.
- **Bẫy 2: thẻ chỉ có thuộc tính thì có thể không bao giờ tới hàm `process_*`.**
  Bộ đọc XML chỉ gửi một thẻ khi nó có thẻ con dạng giá trị; thuộc tính lấy từ
  thẻ cha, và việc đó lúc được lúc không (Duplicate được, khôi phục cả khoá thì
  không — `process_quizportal_paper` không được gọi, 242 dòng mốc bị bỏ âm thầm).
  → `<paper>` mang thêm thẻ `<quizid>`. **Thẻ nào cần đọc thì phải có ít nhất một thẻ con giá trị.**
- **Bẫy 3 (khi viết test): khôi phục thành khoá mới lấy lại tên viết tắt của bản sao
  lưu**, trùng thì Moodle tự đổi thành `qptest_1` → bước dọn tìm theo tên đã đặt sẽ
  trượt, để lại bản sao cả khoá trong thùng rác danh mục (đã xảy ra 3 lần, đã xoá).
  Đặt `course_shortname` trong kế hoạch khôi phục, và dọn theo tên thật trong DB.
- Duplicate trong cùng khoá **dùng chung câu hỏi** với đề gốc (hành vi của core):
  sửa một câu trong ngân hàng là cả hai đề đổi theo.
- **Bản sao lưu cũ không cứu được:** tạo trước 2026-09-22 thì không có dữ liệu
  TOEIC. Thùng rác khoá `#2` còn 3 mục như thế (đề `#3` cũ + 2 "ZIP TEST"), tự hết
  hạn 28/09. Khôi phục chúng → quiz thường không âm thanh; phải nhập lại từ `.zip`.
- Trang kết quả nhập đề đã đổi lời khuyên: nay **được** Duplicate/sao lưu.

**Đã kiểm chứng** (`tools/exam_e2e/backup_check.php`, 20 phép kiểm): cả 3 đường
đều ra đủ 242 dòng, đúng câu hỏi, cùng mã băm file nghe, qua phép kiểm của trang
làm bài; trạng thái phần nghe đi theo lượt làm với cùng mốc thời gian. Trọn bộ 7
bước chạy sạch **198/198**.

### B2 — Trình tạo lớp ✅ (2026-09-22)

Quản trị › Khoá học › **Tạo lớp TOEIC** (`newclass.php`). Một trang: tên + mã lớp,
danh mục, giáo viên (ô tìm người dùng), danh sách học viên dán từ Excel, chọn đề
kèm ngày mở/đóng → **Kiểm tra trước** (không tạo gì) → **Tạo lớp** → trang kết quả
có bảng tài khoản **in được** để phát cho học viên.

**Quyết định của người dùng (2026-09-22) — đừng bàn lại:**
- **Mỗi lớp là một khoá học riêng.**
- **Tên đăng nhập = email, mật khẩu đầu = ngày sinh DDMMYYYY** (19/10/2004 → `19102004`).
  Người dùng ghi ví dụ "19/10/2024 → 19102004" — đã hiểu là gõ nhầm năm.
- Lịch: **chỉ ngày mở/đóng đề**, không giới hạn lượt.
- Mật khẩu ngày sinh **không đạt chính sách mật khẩu** của site (8 ký tự, hoa,
  thường, số, ký tự đặc biệt). Người dùng chọn: **vẫn tạo, giữ chính sách**, không
  bắt đổi mật khẩu lần đầu. Chính sách vẫn áp khi học viên tự đổi mật khẩu.
- Tên hiện kiểu Việt **"Họ Tên"**; bật **đăng nhập bằng email** cho mọi tài khoản.

**⚠️ Đã đổi 3 cài đặt site (nằm trong CSDL, KHÔNG có trong git — cài lại site phải đặt lại):**

| Cài đặt | Giá trị | Vì sao |
|---|---|---|
| `fullnamedisplay` | `lastname firstname` | "Nguyễn Văn An" thay vì "An Nguyễn Văn" |
| `alternativefullnameformat` | `lastname firstname` | **Bẫy:** giáo viên/admin (có quyền xem tên đầy đủ) dùng cài đặt THỨ HAI này; để `language` thì họ vẫn thấy tên ngược |
| `authloginviaemail` | `1` | Học viên cũ (vd `hv01`) cũng đăng nhập bằng email. An toàn vì `allowaccountssameemail = 0` |

Lệnh: `php.exe -d max_input_vars=5000 admin/cli/cfg.php --name=<tên> --set="<giá trị>"`.

| File | Vai trò |
|---|---|
| `classes/local/roster.php` | Đọc danh sách dán vào: tab/phẩy/chấm phẩy, bỏ dòng tiêu đề, tách "Họ đệm" / "Tên" (chữ cuối), ngày sinh → mật khẩu, tìm tài khoản cũ theo email. Gom **mọi** lỗi kèm số dòng |
| `classes/local/class_builder.php` | Tạo khoá, ghi danh, tạo tài khoản, chép đề (backup/restore hoạt động như "nhập từ khoá khác"), đặt lịch + sự kiện lịch, hiện đề. **Hỏng ở đâu cũng gỡ sạch**: xoá khoá + bản sao thùng rác + tài khoản vừa tạo |
| `classes/form/newclass_form.php` | Form 2 nút (xem trước / tạo), cùng một validation |
| `newclass.php` + `templates/newclass_preview.mustache`, `newclass_report.mustache` | Trang, xem trước, kết quả (lưu `$SESSION`, redirect `?done=` → F5 không tạo lần hai) |

- Học viên **đã có tài khoản** (tìm theo email, rồi theo username): chỉ ghi danh,
  **không đổi gì**, kể cả mật khẩu; dòng của họ chỉ cần email.
- Đề chép sang lớp mới được **bật hiển thị** (đề gốc có thể đang ẩn); không đặt
  ngày thì mở ngay, không đóng.
- Bảng lớp nay **sắp theo tên** (An, Bình…), không theo họ: `class_report` đặt
  `sortname` = "tên họ", cột tên của `classboard.js` sắp theo nó.

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`user_create_user()` KIỂM chính sách mật khẩu** và ném lỗi với `19102004`. Tạo
  tài khoản **không mật khẩu** rồi `update_internal_user_password()` (hàm này không kiểm).
- **`trim($s, "\u{00a0}")` là cắt theo BYTE**: byte thứ hai của NBSP (`A0`) cũng là
  byte cuối của chữ "à" → "Hà" bị cắt hỏng. Thay nguyên cụm NBSP bằng `str_replace` trước.
- Nhận dòng tiêu đề phải **chặt** (ô đầu đúng là "Email"): quy tắc "có chữ email" đã
  nuốt âm thầm dòng lỗi `not-an-email`. Test bắt được.
- `class` gắn cho một phần tử form Moodle nằm ở **khung bao**, không phải thẻ
  `textarea` → CSS phải là `.lớp textarea`; đặt lên khung thì nhãn cũng đổi font.
- Thử đăng nhập sai từ CLI **tăng bộ đếm đăng nhập sai** của tài khoản thật
  (`login_failed_count_since_success`) — đã xoá cho `hv01`. Site tắt khoá tài khoản
  (`lockoutthreshold = 0`) nên vô hại, nhưng đừng thử trên tài khoản thật.

~~Còn mở: tên lớp trên thẻ đề; thêm học viên vào lớp đã có~~ — **cả hai xong
2026-09-22** (mục kế tiếp).

**Đã kiểm chứng:** trọn bộ 9 bước chạy sạch **242/242** (tạo lớp CLI 30, tạo lớp
Chrome 14, cộng 198 của các bước cũ).

### Tên lớp trên thẻ đề + B4 — Quản lý học viên ✅ (2026-09-22)

**Tên lớp trên thẻ đề:** học viên học **từ hai lớp** thì mỗi thẻ đề có một dòng tên
lớp (tên khoá học) phía trên tên đề — `dashboard_repository::get_for_user()` đặt
`classlabel`. Học một lớp thì không: tên lớp đã ở thanh trên cùng, lặp lại trên mọi
thẻ chỉ thêm nhiễu. (Thanh trên cùng ẩn tên lớp ở ≤ 600px — việc cũ, chưa đổi.)

**B4:** Quản trị › Khoá học › **Quản lý học viên** (`students.php`). Lối vào khác:
menu "Xem thêm" của khoá học (chỉ admin), link dưới tên lớp ở "Các lớp luyện TOEIC",
nút trên trang kết quả tạo lớp. Một trang: chọn lớp → danh sách học viên (ngày
sinh, lớp khác đang học) với nút **Đặt lại mật khẩu** / **Chuyển lớp** trên từng
dòng (mở khung ngay trên danh sách, dòng đó được đánh dấu) → ô **Thêm học viên**
ở cuối, cùng cách dán danh sách + "Kiểm tra trước" như tạo lớp → bảng tài khoản in được.

| File | Vai trò |
|---|---|
| `classes/local/class_members.php` | `students()`, `plan()` (mới / ghi danh / đã trong lớp / quay lại lớp), `add()`, `reset_password()`, `move()`, `cannot_reset()`, `cannot_move()`. Tự kiểm quyền |
| `classes/local/birthdate.php` | Ngày sinh ở trường hồ sơ `ngaysinh`: `ensure_field()`, `get()`/`get_many()`, `set()`, `password()` |
| `students.php` + `classes/form/{addstudents,resetpassword,movestudent}_form.php` | Trang + 3 form |
| `templates/students_page`, `students_preview`, `students_added.mustache` | Giao diện |
| `templates/account_sheet.mustache` | Bảng tài khoản in được — **tách từ `newclass_report`**, hai trang dùng chung. Class CSS đổi `quizportal-newclass__print` → `quizportal-accounts` |
| `db/install.php` (mới) + bước upgrade `2026092202` | Tạo trường hồ sơ "Ngày sinh" |
| `class_builder::enrol_students()` | Vòng "tạo tài khoản + ghi danh" tách ra, B2 và B4 dùng chung. Tài khoản mới **lưu ngày sinh** |

Plugin version **2026092202** (đã chạy upgrade — trường hồ sơ đã có, đã kiểm).

**Mặc định Claude tự chọn — người dùng CHƯA duyệt, đổi được:**
- **Lưu ngày sinh** vào trường hồ sơ Moodle "Ngày sinh" (`ngaysinh`, danh mục
  "PTEducation"), dạng chữ `DD/MM/YYYY`, **học viên không sửa được**, chỉ học viên +
  admin thấy. Trước đó ngày sinh chỉ biến thành mật khẩu rồi bỏ đi → không có gì để
  "đặt lại về". Trường hồ sơ core thì xoá tài khoản tự dọn, upload người dùng hàng
  loạt điền được (`profile_field_ngaysinh`), admin sửa được ở trang hồ sơ. Chữ chứ
  không phải `datetime`: timestamp nửa đêm đọc ở múi giờ khác thành ngày hôm trước.
- Tài khoản **chưa có ngày sinh** (`hv01`–`hv10` và mọi tài khoản tạo trước hôm nay):
  lúc đặt lại mật khẩu, trang hỏi ngày sinh rồi lưu cho lần sau.
- **Chuyển lớp = đình chỉ ghi danh ở lớp cũ, không xoá.** Lượt làm, điểm, nhóm ở lớp
  cũ còn nguyên; bảng lớp và dashboard chỉ đọc ghi danh đang hoạt động nên tự thôi
  hiện lớp cũ. Chuyển ngược về → bật lại đúng ghi danh cũ, thấy lại bài cũ.
- Thêm học viên **đã có tài khoản**: như B2, chỉ ghi danh, không đổi gì (có ghi ngày
  sinh trên dòng cũng không lưu).
- Đặt lại mật khẩu **không** đá phiên đang đăng nhập (đang thi ở máy khác thì không
  văng ra), **có** xoá bộ đếm đăng nhập sai. Không bắt đổi mật khẩu lần đầu (như B2).
- Trang chỉ dành cho admin (`moodle/user:update` cấp hệ thống).

**Luật — đừng phá:**
- "Học viên của lớp" = `class_report::load_students()` (nay `public`) — **một** định
  nghĩa cho bảng lớp và trang học viên. **Đừng dùng `is_enrolled($ctx, $uid,
  'mod/quiz:attempt')`**: site admin có mọi quyền nên hàm trả `true` cho admin được
  ghi danh làm giáo viên (đã kiểm ở khoá `#10`). `get_enrolled_users()` lọc theo vai
  trò nên không dính.
- Thêm học viên chạy trong **một transaction** (hỏng giữa chừng: không còn tài khoản
  nào). B2 vẫn gỡ tay như cũ vì tạo khoá + backup/restore không bọc transaction được.

**Bẫy đã kiểm chứng, đừng dẫm lại:**
- **`moodleform` nhận action là `moodle_url` thì mất anchor**: `MoodleQuickForm` gọi
  `out_omit_querystring()`, tham số thành input ẩn, `#them-hoc-vien` rơi mất → bấm
  "Kiểm tra trước" xong trang mở ở đầu, còn bảng xem trước nằm tít dưới. Truyền
  **chuỗi** `$url->out(false)`.
- **`.sr-only` (`position: absolute`) trong `.table-responsive` thoát khỏi khung cuộn**
  và kéo cả trang rộng thêm 32px ở 390px. Cho khung `position: relative`. Tiện thể
  bắt được `classlist.php` (B3) tràn 11px vì bảng không có khung cuộn — đã vá.
- Heredoc **lần thứ 6**: `\s` trong Python chạy qua heredoc → chuỗi cũ không khớp.
  Lần này `assert chuỗi_cũ in s` chặn trước khi ghi nên không hỏng gì. **Script sửa
  file nào cũng phải assert chuỗi cũ có mặt.**
- Upgrade in ra một loạt `auth_db/field_*_profile_field_ngaysinh` — bình thường, core
  tạo cho mọi trường hồ sơ mới.

**Đã kiểm chứng:** trọn bộ **11 bước chạy sạch 312/312** từ fixture mới (trang làm
bài 66, chấm điểm 22, bảng lớp CLI 27, sao lưu 20, tạo lớp CLI 30, **quản lý học viên
CLI 40**, trang kết quả 27, bảng quy đổi 14, bảng lớp Chrome 22, tạo lớp Chrome 14,
**quản lý học viên Chrome 30**). Ảnh chụp đã duyệt ở 1366px và 390px. Sau teardown:
`slotmeta` chỉ đề `#9`/`#17`, `attemptstate` chỉ 3 lượt của `hv01`, 0 tài khoản `qp_`,
0 khoá `qptest*`, thùng rác trống, `siteadmins` = 2, bảng quy đổi mặc định, 0 dòng
`user_info_data` (chưa tài khoản thật nào có ngày sinh).

## 4. Việc tiếp theo

Lộ trình đầy đủ: **`moodle-ptedu-roadmap.md`**. Thứ tự khuyến nghị:

| Ưu tiên | Việc | Ghi chú |
|---|---|---|
| ~~1~~ ✅ | ~~B1: Trình nhập đề TOEIC hàng loạt~~ — **xong 2026-09-21** (xem mục 3) | Trang nhập đề, importer, phục vụ file nghe. Backup/restore xong 22/09 |
| ~~2~~ ✅ | ~~A6 + A2: Trang làm bài + audio phát một lần~~ — **xong 2026-09-22** (xem mục 3) | Còn treo: người thật làm thử một đề trọn vẹn có tai nghe; Safari/Firefox |
| ~~3~~ ✅ | ~~A7 + A3: Trang kết quả + quy đổi điểm 10–990~~ — **xong 2026-09-22** (xem mục 3) | Còn treo: trung tâm có muốn thay bảng Oxford bằng bảng riêng không (sửa ở `scale.php`) |
| ~~4a~~ ✅ | ~~B3: Bảng điều khiển lớp~~ — **xong 2026-09-22** (xem mục 3) | Còn mở: link tới bảng lớp từ dashboard của cổng (giáo viên đăng nhập qua cổng đang rơi vào dashboard học viên) — **việc của giáo viên, để sau** |
| ~~4b~~ ✅ | ~~Sao lưu/khôi phục cho plugin~~ — **xong 2026-09-22** (xem mục 3) | Chép đề sang lớp khác nay an toàn (Duplicate / sao lưu / sao chép khoá) |
| ~~4c~~ ✅ | ~~B2: Wizard tạo lớp~~ — **xong 2026-09-22** (xem mục 3) | Tên lớp trên thẻ đề: xong cùng B4 |
| ~~5~~ ✅ | ~~B4: Quản lý học viên một trang~~ — **xong 2026-09-22** (xem mục 3) | Còn treo: người dùng duyệt các mặc định (lưu ngày sinh, chuyển lớp = đình chỉ) |
| **6 — TIẾP THEO** | Track C: dọn dẹp | Hỏi người dùng trước khi bắt đầu |
| **Song song** | **Đưa lên mạng** — xem `moodle-ptedu-deploy.md` | 3 việc gấp, đều miễn phí: push code lên private repo (code **chỉ có trên máy này**), đo băng thông file nghe, thử Safari/iPhone |

> **Mọi thứ về giáo viên — để sau** (người dùng dặn 2026-09-22): chức năng và giao
> diện cho giáo viên (lối vào cho giáo viên đăng nhập qua cổng, cho giáo viên tự
> nhập đề / quản lý học viên…) **chưa làm, chưa đề xuất**. Người dùng sẽ báo khi
> nào bắt đầu. Các trang quản trị hiện chỉ dành cho admin.

### Sự thật đã kiểm chứng — đừng tra lại

- **Không có qtype nào nhóm nhiều câu hỏi chung một ngữ liệu** (Part 3,4,6,7). Đã
  duyệt hết `question/type/`. `multianswer` (cloze) **không** thay thế được.
  → Hướng chọn: qtype `description` làm khối ngữ liệu + renderer riêng gom theo section.
- **`QUIZ_NAVMETHOD_SEQ` có sẵn** (`mod/quiz/lib.php:77`) → khoá không cho quay lại
  câu trước đã làm được, phục vụ Part 1–4.
- **`quiz_sections` có sẵn** (`quizid`, `firstslot`, `heading`, `shufflequestions`)
  → mô hình 7 Part bằng section hợp lý hơn tag. Dashboard hiện dùng tag; nên dùng **cả hai**.
- **Moodle chấm điểm thô** (`178/200`), không có thang TOEIC 10–990 → cần lớp quy đổi.

### Còn dang dở / chưa kiểm chứng

- **Trình kiểm tra im lặng khi thiếu `audio_start`.** Câu Part 1–4 để trống cột
  này vẫn qua, không có cả cảnh báo. Nên thêm **một** cảnh báo gộp (không phải
  lỗi: hướng dẫn cho phép điền mốc sau cùng).
- **Đề thật đầu tiên: `D:\Learn\lam_viec\moodle\De_1\DE_01.xlsx`** (+ `media/`,
  `Test_01.mp3`). Đủ 200 câu, 42 ngữ liệu, qua trình kiểm tra 0 lỗi 0 cảnh báo.
  Dùng file này để chạy thử importer. Câu 42–200 do Claude chép từ PDF scan, đáp
  án lấy từ ảnh; bản gốc trước khi điền là `DE_01.truoc-khi-dien.xlsx`.
  **Phần nghe đã hoàn chỉnh (2026-09-21, phiên 6):** lời thoại Part 1–4 chép lại
  từ `Script - ETS 2024 LC.pdf` (Test 1 = trang 2–31), **100/100 `audio_start`**,
  tiêu đề nhóm đúng lời đọc. Bản trước khi sửa: `De_1/backup/`. Chi tiết ở nhật ký.
  Trên site: **quiz `#9`** (cmid 10, khoá `#2`, ẩn) — `#3` cũ đã xoá (phiên 7).
- **`Script - ETS 2024 LC.pdf` chứa script của cả 10 đề** (296 trang scan, bản Hàn,
  mỗi đề ~30 trang; Test 2 bắt đầu trang 32, Test 6 trang 149). Lớp OCR sẵn có là
  rác lẫn tiếng Hàn — phải render trang thành ảnh rồi đọc. Số câu in chỉ số trên
  (`³²Thank you`) là mốc gợi ý đáp án của sách, **không phải lời thoại** — chính
  nó sinh ra lỗi `50I'm` khi copy-paste.
- **Quy ước nội dung mà renderer/importer phải hiểu** (xuất hiện trong DE_01):
  ô trống Part 5 = `___`; ô trống Part 6 = `___(131)___` ngay trong ngữ liệu, còn
  `text` của câu là `Chỗ trống (131)`; Part 7 nhiều tài liệu thì ngăn bằng một
  dòng `* * *`; vị trí chèn câu = `— [1] —`.
- **`validate_workbook.php` cần MySQL chạy** vì nạp đủ `config.php`. Khi WAMP
  tắt thì dùng script bọc: `define('ABORT_AFTER_CONFIG', true)` rồi `require` file
  CLI. Classloader được đăng ký ở `lib/setup.php:591`, trước điểm dừng ở dòng 601,
  nên PhpSpreadsheet vẫn nạp được. Lệnh trong `HUONG-DAN-NHAP-DE.md` ghi `php …`
  trần, nhưng lệnh đó không chạy được trên máy này.
- **4 mục cần tài khoản học viên thật để test thủ công** — xem `PROGRESS.md` mục 5
  (cờ `confirmed`, cookie ghi nhớ, `wantsurl`, không hồi quy dashboard).
- **Chưa xem theme mới bằng mắt.** Đã kiểm chứng bằng máy: CSS biên dịch 902 KB,
  `#14303a` xuất hiện 208 lần, `#ded8cd` 141 lần, 40 custom property `--pt-*`,
  22 `@font-face`, các trang trả 200. Nhưng **chưa ai nhìn trang thật** — cần
  duyệt lại bằng mắt, nhất là trang admin và trang khoá học. *(Riêng trang làm
  bài đã được duyệt qua ảnh chụp Chrome ở cả 1366px và 390px, 2026-09-22.)*
- **Người thật đã đi trọn luồng một lần (2026-09-22)**: học viên `hv01`, quiz
  `#17` khoá `#10`, lượt `#30`, 16:05–16:14 — nhập đề → làm bài → trang chuyển
  tiếp → phần đọc → nộp → kết quả. Autosave ghi 10 lần. Điểm 25/990 (7 đúng, 3 sai,
  190 bỏ trống); `result::for_attempts()` khớp `sumgrades` thô = 7. **Phần nghe đã
  được tua** (cả lượt chỉ 9 phút) → vẫn chưa ai nghe trọn 46 phút có tai nghe.
  **Chưa hỏi người dùng thấy lỗi gì** trong lượt thử này.
- **Bộ component trong `post.scss` mới có phần tối thiểu** (`.btn-pt-accent`,
  `.pt-pill`, `.pt-label`, focus ring). Còn thiếu: bảng, form, trạng thái rỗng,
  phân trang — bổ sung dần khi dựng trang mới.
- Nợ kỹ thuật khác: `PROGRESS.md` mục 4.

## 5. Bản đồ tài liệu

| File | Nội dung |
|---|---|
| **`CLAUDE.md`** ← đang đọc | Trạng thái tổng, môi trường, việc tiếp theo |
| `moodle-ptedu-roadmap.md` | Lộ trình đầy đủ 3 track + chẩn đoán thiết kế |
| `moodle-ptedu-deploy.md` | **Đưa lên mạng**: chọn server cho 200 người thi cùng lúc, nút thắt file nghe 42 MB, checklist 5 giai đoạn |
| `local/quizportal/PROGRESS.md` | Chi tiết cổng học viên: dữ liệu thật/giả, lỗi đã vá, nợ kỹ thuật |
| `local/quizportal/tools/README.md` | Công cụ dò mốc `audio_start` tự động + soát lời thoại bằng audio (cần Python + `faster-whisper`) |
| `local/quizportal/tools/exam_e2e/README.md` | Bộ test 11 bước (CLI + Chrome thật): trang làm bài, chấm điểm, trang kết quả, bảng quy đổi, bảng lớp, sao lưu đề, tạo lớp, quản lý học viên. Cần Node + `npm install`; kèm script dựng/xoá khoá thử |
| `local/quizportal/samples/HUONG-DAN-NHAP-DE.md` | Hướng dẫn nhập đề cho người nhập liệu |
| `moodle-dashboard-design-notes.md` | Ghi chú thiết kế gốc (bảng màu cũ navy/đỏ — **đã thay**, giữ để tham chiếu cấu trúc 7 Part) |

## 6. Quy ước

- **Không sửa file core Moodle.** Đã từng lỡ sửa `README.md` và `config-dist.php`,
  phải revert. Mọi tuỳ biến nằm trong `theme/ptedu` và `local/quizportal`.
- **Git: làm việc trên nhánh `ptedu`** (tạo 2026-09-22, tách từ `MOODLE_405_STABLE`).
  Đừng commit lên `MOODLE_405_STABLE` — đó là nhánh theo dõi Moodle gốc, giữ sạch để
  nâng cấp core bằng `git merge`. `origin` là `git.moodle.org` (chỉ đọc) → **chưa có
  remote riêng**, commit mới chỉ nằm trên máy này.
- **`config.php` không nằm trong git** (`.gitignore` của Moodle). Cài lại site thì
  phải tự đặt lại `$CFG->alternateloginurl` (hiện ở `config.php:24`) — không có nó
  cổng học viên không được gắn vào site.
- Chuỗi giao diện viết **tiếng Việt có dấu**. Chuỗi của core dùng `get_string()` —
  site đang chạy gói ngôn ngữ tiếng Việt nên đã ra tiếng Việt sẵn.
- Thêm màu mới thì **phải kiểm tra tương phản WCAG** trước khi dùng, ghi tỉ lệ
  vào comment cạnh token.
- Đổi theme về Boost nếu cần:
  `php.exe -d max_input_vars=5000 admin/cli/cfg.php --name=theme --set=boost`

---

## Nhật ký

### 2026-09-23 (phiên 12) — kế hoạch đưa lên mạng
- Người dùng hỏi: mua server nào cho **200 học viên thi cùng lúc**, và cần làm gì để
  public dự án. Trả lời + viết thành **`moodle-ptedu-deploy.md`** (checklist 5 giai
  đoạn, tích dần). Không sửa code.
- **Phát hiện chính: nút thắt là file nghe, không phải CPU.** `Test_01.mp3` =
  44.109.018 byte (42 MB, 128 kbps) và **đi qua PHP** vì `local_quizportal_pluginfile()`
  phải chạy `can_listen()` mỗi request. Nếu trình duyệt stream đúng nhịp thì 200 người
  chỉ ~26 Mbps; nếu tải nguyên file trong 5 phút thì ~235 Mbps. **Chênh 20 lần, chưa
  ai đo.** Khuếch đại thêm vì `send_stored_file(..., 0, 0, ...)` đặt lifetime 0 →
  không cache → mỗi lần F5 là tải lại.
- **Đã xác minh `$CFG->xsendfile` dùng được** với code hiện tại: `send_stored_file()`
  → `file_system::supports_xsendfile()` (`lib/filestorage/file_system.php:499`). Bật
  nó thì PHP không bị giữ worker suốt lúc truyền. Gần như bắt buộc ở quy mô này.
- Cách giảm tải thứ hai, không đổi code: mp3 **128 kbps stereo → 64 kbps mono** cắt
  một nửa băng thông (đề TOEIC chỉ là giọng nói).
- **Rủi ro đặc thù đã ghi vào file mới:** đồng hồ nghe chạy theo server
  (`bây giờ − listenstart`) → server sập 10 phút giữa phần nghe là cả lớp mất vĩnh
  viễn 10 phút băng. `cli/listening_clock.php` chỉ sửa được **từng học viên một**.
  → Nên thêm lệnh "lùi băng cho cả lớp" trước khi public (ước lượng nửa buổi).
- Xác nhận yêu cầu Moodle 4.5 từ tài liệu chính thức: **PHP 8.1–8.3** (64-bit,
  ext `sodium`), MariaDB ≥ 10.6.7 / MySQL ≥ 8.0 / PostgreSQL ≥ 13. 4.5 là LTS nhưng
  **đã hết hỗ trợ sửa lỗi chung**, chỉ còn vá bảo mật.
- Đề xuất cấu hình: **8 vCPU / 16 GB / 200 GB NVMe, đặt máy ở Việt Nam** (cáp biển
  đứt = thảm hoạ với bài thi có đồng hồ theo server). Đỉnh CPU là lúc 200 người cùng
  bấm "Bắt đầu" (render 123 câu/trang), không phải lúc đang thi → chia ca lệch 2–3 phút.
- **3 việc gấp nhất, đều miễn phí:** push code lên private repo (hiện code CHỈ có trên
  máy này), đo băng thông file nghe bằng DevTools, thử Safari/iPhone (chưa từng thử —
  nếu Safari chặn autoplay thì phần nghe hỏng hoàn toàn trên iPhone/Mac).
- **Vẫn chưa commit git** (B3, sao lưu, B2, B4 từ các phiên trước, cộng file mới này).

### 2026-09-22 (phiên 11) — tên lớp trên thẻ đề, B4 quản lý học viên
- Báo cáo tiến độ. **Người dùng yêu cầu 2 việc:** tên lớp trên thẻ đề (học viên học
  2 lớp) và B4 (thêm học viên vào lớp có sẵn, đặt lại mật khẩu về ngày sinh, chuyển
  lớp). **Dặn: mọi thứ về giáo viên để sau**, họ sẽ báo khi nào làm.
- Cả hai xong (mục 3). Plugin **2026092202**, đã upgrade: tạo trường hồ sơ "Ngày sinh"
  (`user_info_field` #1, danh mục `PTEducation` #1). Đây là thay đổi CSDL duy nhất.
- Sửa phần B2: `class_builder::enrol_students()` tách ra dùng chung, tài khoản mới lưu
  ngày sinh, bảng tài khoản in được tách thành `account_sheet.mustache`, trang kết quả
  tạo lớp có nút "Quản lý học viên". `newclass_test.js` đổi selector + nay kiểm tên lớp
  trên thẻ đề.
- Vá kèm: `classlist.php` tràn ngang 11px ở 390px (từ B3).
- Bộ test: thêm `students_check.php` (40), `students_test.js` (30), `fixture.php
  second-class` (lớp đích thứ hai để thử chuyển lớp — **không bao giờ** chuyển tài khoản
  thử vào lớp thật).
- **Chưa hỏi người dùng** về các mặc định đã chọn (mục 3, B4). **Chưa commit git**
  (B3, sao lưu, B2, B4 đều đang chờ).

### 2026-09-22 (phiên 10) — báo cáo tiến độ, cờ đánh dấu, git, B3 bảng lớp, sao lưu đề, B2 tạo lớp
- Báo cáo: kiểm tra DB — site có 3 khoá (`#1`, `#2`, `#10`), 3 quiz (`#1 TEST`,
  `#9` ẩn, `#17` hiện), đúng 1 lượt làm thật (`#30`, xem mục 4 "Còn dang dở").
  `import.php` đã được thử bằng trình duyệt (mục 3, "Còn treo sau B1").
- **Người dùng yêu cầu cờ đánh dấu câu chưa chắc** → làm xong (mục 3, "Cờ đánh
  dấu"). Sửa: `exam_page.php`, `result_page.php`, 6 template + `flag_icon.mustache`
  mới, `exam.js`, `result.js` (đã chép sang `amd/build`), `styles.css`, `exam_test.js`.
- Test trọn bộ trên khoá thử: **66/66 · 22/22 · 27/27 · 14/14**, duyệt ảnh chụp ở
  1366px và 390px. Sau teardown: đề `#9`/`#17` mỗi đề 242 `slotmeta`,
  `attemptstate` chỉ còn lượt `#30` của `hv01`, thùng rác trống, `siteadmins` = 2,
  bảng quy đổi mặc định.
- **Commit git lần đầu** trên nhánh mới `ptedu`: 3 commit (theme, plugin, tài
  liệu). Loại `tools/__pycache__/` (thêm `tools/.gitignore`); `node_modules/` và
  `shots/` của bộ test đã có `.gitignore` sẵn. Rà mật khẩu: chỉ có mật khẩu của hai
  tài khoản test tạm (`fixture.php`), bị xoá sau mỗi lần chạy. Chưa push đâu cả.
- Tên repo đề xuất `ptedu-toeic` (một repo cả site, private) — **người dùng chưa tạo
  repo GitHub**, chưa push.
- **B3 — bảng điều khiển lớp: xong** (mục 3). Version 2026092201, đã upgrade. Test
  trọn bộ 178/178, teardown sạch: `slotmeta` chỉ đề `#9`/`#17`, 0 tài khoản `qp_`,
  0 nhóm, thùng rác trống, `siteadmins` = 2, bảng quy đổi mặc định.
- Thấy trong DB: `hv01` có thêm lượt `#36`, `#37` (17:28, 17:32) — người dùng tự thử
  nút cờ. Bảng lớp khoá `#10` nay có dữ liệu thật để xem.
- **Sao lưu/khôi phục đề: xong** (mục 3). Không đổi CSDL, không bump version.
  Lần đầu khôi phục cả khoá mất 242 dòng mốc (bẫy thẻ chỉ có thuộc tính) — test bắt
  được, đã vá. Test để lại 3 bản sao khoá `qptest_1` trong thùng rác danh mục — đã
  xoá, và sửa cả test lẫn `fixture.php teardown` để không lặp lại. Trọn bộ 198/198.
- **B2 — trình tạo lớp: xong** (mục 3). Người dùng chốt: lớp = khoá; username = email,
  mật khẩu = ngày sinh DDMMYYYY; lịch chỉ mở/đóng; giữ chính sách mật khẩu; tên
  "Họ Tên"; đăng nhập bằng email. **Đã đổi 3 cài đặt site** (bảng ở mục 3 — không có
  trong git). Plugin không đổi CSDL/version. Trọn bộ 9 bước **242/242**.
- **B3, sao lưu/khôi phục và B2 chưa commit git.**

### 2026-09-22 (phiên 9) — A7 + A3: trang kết quả + quy đổi điểm
- Tìm bảng quy đổi: sách ETS của đề 1 không kèm bảng; chọn bảng Oxford English
  Testing. **Người dùng chốt:** Oxford làm mặc định, admin sửa được, hiện một con số.
- Viết `score_scale`, `result`, trang kết quả, trang sửa bảng, nối dashboard,
  router chặn thêm `review.php`. Không đổi CSDL.
- Bộ test `tools/exam_e2e/` thêm `score_check.php`, `result_test.js`,
  `scale_test.js`; `fixture.php` thêm `admin-on`/`admin-off` và teardown an toàn
  với admin. Chạy trọn bộ trên khoá thử rồi xoá; DB về nguyên trạng.
- **Người dùng bắt đầu tự thử trọn luồng** (khoá `#10`, học viên `hv01`, đã hướng
  dẫn từng bước trong chat). Để khỏi ngồi nghe 46 phút, thêm
  **`cli/listening_clock.php`**: `--user=hv01 --end` đưa băng tới hết (F5 là sang
  trang chuyển tiếp), `--to=MM:SS` nhảy tới mốc, không tham số thì xem băng đang ở
  đâu. Dựa trên `attempt_state::set_listening_position()` (mới, chỉ dùng để thử).
  Đã thử trên khoá riêng; chưa chạy trên lượt của người dùng. Mốc đề 1: Part 3
  bắt đầu 14:06, Part 4 bắt đầu 32:31, nhóm cuối 44:33, băng dài 45:53.
- Chưa commit git.

### 2026-09-22 (phiên 8) — A6 + A2: trang làm bài
- **Người dùng chọn phần nghe "liền mạch như thi thật"** (thay vì từng câu bấm
  Tiếp). Toàn bộ thiết kế và bẫy ở mục 3, "A6 + A2".
- Viết trang làm bài, router chặn script core, bảng `local_quizportal_attemptstate`
  (version 2026092200, đã upgrade), module JS, 5 template, CSS.
- **Vá lỗi có từ B1:** xoá cả khoá học để lại `slotmeta` mồ côi (observer mới
  cho `course_content_deleted`). Bắt được nhờ bước kiểm tra sau khi dọn khoá thử.
- **Vá lỗi theme:** favicon 404 trên mọi trang (thêm `theme/ptedu/pix/favicon.ico`).
- Chỉnh giao diện sau khi xem ảnh chụp: ảnh Part 1 giới hạn 48vh để bong bóng
  không bị đẩy khỏi màn hình khi đang nghe; đáp án Part 3/4 xếp 2 cột; link trong
  bài đọc thành chữ thường; nút "Nộp bài" lên thanh trên cùng; thanh trên cùng
  vừa màn hình 390px.
- Bộ test Chrome đưa vào `tools/exam_e2e/`. Test dùng khoá học + tài khoản thử
  riêng, **đã xoá hết** (cả bản sao trong thùng rác danh mục). DB về nguyên trạng:
  đề `#9` không có lượt làm nào, `attemptstate` rỗng.
- Chưa commit git — toàn bộ phần tuỳ biến vẫn untracked như đầu phiên.

### 2026-09-21 (phiên 7) — thay đề `#3`, công cụ dò mốc tự động
- **Xoá quiz `#3`** (cmid 4) + 242 câu hỏi + category `#14` qua API core
  (`course_delete_module`, `question_delete_question`, `core_question\category_manager`),
  0 lượt làm bài, không nơi nào khác dùng các câu này; slotmeta + file nghe về 0.
  **Nhập lại** `DE_01.xlsx` đã sửa → **quiz `#9` / cmid 10**, ẩn, category giữ đúng
  tên. Kiểm chứng: 100/100 slotmeta có `audiostart`, 0 lời thoại dính chữ, thanh
  7 Part 200 câu = 100%.
- **Thùng rác khoá `#2` có 3 mục** (bản sao `#3` + 2 "ZIP TEST" của phiên 2 — ghi
  chú cũ nói đã xoá hẳn là chưa đúng, chúng nằm trong thùng rác). Tự hết hạn sau
  7 ngày. Khôi phục từ thùng rác sẽ **thiếu file nghe** (plugin chưa có backup).
- **Công cụ mới** (hướng dẫn: `local/quizportal/tools/README.md`):
  `tools/audio_marks.py` (Python + faster-whisper: dò 100 mốc + tiêu đề nhóm,
  tự kiểm 5 điều kiện, dừng nếu trượt) và `cli/apply_audio_marks.php` (ghi vào
  xlsx, chạy thử mặc định, sao lưu vào `backup/`, từ chối khi Excel đang mở; **và
  đối chiếu lời thoại trong file đề với audio**). Bước 3 của `HUONG-DAN-NHAP-DE.md`
  đã trỏ tới đây.
- **Kiểm chứng công cụ:** chạy từ đầu không cache (327 s CPU) → 100 mốc + 23 tiêu
  đề **giống hệt** bộ đã kiểm tay; xoá "Number 21/29" khỏi bản nhận dạng → tự nghe
  lại ra đúng mốc; tắt nghe lại → Part 1/2 suy từ cấu trúc ra đúng mốc, nhóm 68
  báo lỗi và thoát 1; gài lại mốc sai cũ 30:17 → bị bắt. Công cụ PHP: file đã sửa
  → 0 thay đổi, chỉ nhắc 2 mục (tên riêng, "8 a.m."); **bản cũ trước khi sửa → tự
  chỉ ra 36 mục hỏng** (Part 1 trống, chữ dính, `50I'm`, lỗi kiểu OCR) + 15 tiêu
  đề; ghi thật trên bản sao → 115 ô, chạy lại 0 thay đổi, validator hợp lệ.
- **Bẫy mới (đã ghi trong README công cụ):** (1) đoạn cắt để nghe lại phải bắt đầu
  **1 s trong khoảng lặng** — cắt sát tiếng thì Whisper nuốt "Number 21"; gợi ý
  `initial_prompt` còn tệ hơn. (2) Trong đoạn cắt bắt đầu bằng im lặng, Whisper kéo
  từ đầu tiên về sát đầu đoạn → phải **neo vào con số**, không vào "Questions";
  lỗi này ra mốc 30:17 thay vì 30:30 mà vẫn qua 4 phép kiểm cũ → đã thêm phép
  "tiếng đọc không sớm hơn con số quá 2,5 s". (3) GPU hỏng lần đầu **không phải
  do CUDA** mà do symlink khi tải model (WinError 1314); nay GPU thiếu
  `cublas64_12.dll` → tự lùi CPU.
- **Không bắt được:** nhãn người nói bị đảo — vẫn phải đọc lướt.

### 2026-09-21 (phiên 6) — sửa phần nghe của `DE_01.xlsx`
- **Lời thoại:** đọc 30 trang ảnh của PDF script, chép lại Part 1 (trước đó trống),
  Part 2 (25 câu, mỗi câu một kiểu, dính chữ) và 23 đoạn Part 3/4. Định dạng thống
  nhất: mỗi lượt lời một dòng `W-Br: …`; Part 1 `M-Au: (A) …` rồi `(B) …` mỗi dòng
  một phương án; Part 2 `câu hỏi` + `người đáp: (A) …`. Sửa nhãn đảo ở `p3_07`
  (người gọi là M-Au, Bianca là W-Am). 4 đoạn (`P4_03/04/05/08`) người dùng nhập
  đã khớp từng ký tự nên không đổi. Đáp án 100 câu nghe khớp bảng đáp án in trong PDF.
- **Tiêu đề nhóm sai:** cả 10 đoạn Part 4 ghi "conversation"; nay theo đúng lời
  đọc: recorded message, broadcast, talk, speech, tour information, excerpt from
  a meeting, speech and map, talk and chart; Part 3 thêm "with three speakers",
  "and Web site / list / map".
- **`audio_start` 100/100** — cài `faster-whisper` 1.2.1 (pip, Python 3.12 của
  người dùng; model `small.en` 464 MB ở `~/.cache/huggingface/hub`), chạy CPU
  6,6 phút cho 46 phút audio (đường CUDA không chạy được, tự lùi về CPU). Part 1/2
  = lúc đọc "Number N"; Part 3/4 = lúc đọc "Questions X through Y…", chung cho 3 câu.
- **Bẫy của Whisper, đừng tin mốc thô:** (1) trong đoạn nói liền dài, thời điểm
  của từ **trôi vài giây** — câu 27: "number" ghi 704,7 s nhưng thật là 709,5 s,
  mốc thô rơi giữa lời câu 26; (2) Whisper **bỏ sót hẳn** câu "Questions 68
  through 70…", kể cả khi chạy lại riêng đoạn đó — chỉ cắt riêng 5 giây mới nghe
  ra; (3) tách "twenty-one" thành hai token. **Cách làm đúng:** Whisper chỉ để biết
  câu nào ở vùng nào, mốc thật = điểm cuối khoảng lặng ≥ 0,9 s ngay trước tiếng
  đọc (đo năng lượng khung 50 ms, ngưỡng −40 dB), trừ 0,15 s rồi làm tròn xuống.
  Kiểm chứng: cả 54 mốc phân biệt đều nằm trong im lặng, tiếng đọc bắt đầu ≤ 1,5 s
  sau mốc, tăng dần. Lời thoại PDF so với Whisper khớp 88–100% (chỗ lệch là
  `e-mail/email`, `ten/10`, tên riêng Whisper nghe sai).
- Ghi file: so từng ô với bản sao lưu — đúng 165 ô, chỉ 4 cột (`questions.transcript`
  31, `questions.audio_start` 100, `passages.content` 19, `passages.title` 15),
  comment tiêu đề cột còn nguyên. `validate_workbook.php`: hợp lệ.
- Script làm việc của phiên này đã được viết lại thành công cụ trong plugin ở
  phiên 7 (xem đó).

### 2026-09-21 (phiên 5) — B1 hoàn tất
- **Viết `lib.php` (`local_quizportal_pluginfile()`) + `classes/local/listening.php`.**
  File nghe giờ phục vụ được qua HTTP. Quy tắc ai được nghe ở mục 3.
- Kiểm chứng: `can_listen()` 7/7 với **học viên thật `hocvien1` + lượt làm thật**
  (tạo lượt → được nghe; nộp → được nghe lại vì General feedback bật; tắt General
  feedback → bị từ chối), chạy trong transaction rồi rollback, DB về baseline.
  Gọi thẳng callback như `pluginfile.php`: admin nhận **44.109.018 byte, SHA-1 khớp**
  file gốc; học viên chưa làm bài (đề tạm bỏ ẩn trong transaction) → từ chối;
  sai file area → từ chối. Qua HTTP: URL file nghe 303 về đăng nhập (trước đây là
  404 vì chưa có `lib.php`), sai file area 404.
- **Phát hiện: backup/restore/Duplicate làm mất file nghe + `slotmeta`** (mục 3).

### 2026-09-21 (phiên 4)
- **Viết trang `import.php`** (chi tiết ở mục 3). Kiểm chứng bằng script mô phỏng
  POST: đặt `.zip` vào draft area như filepicker, `import_form::mock_submit()`,
  rồi chạy **chính** `import_form::import()` mà trang gọi — 24/24 đạt: form render,
  4 luật validation, zip thiếu media → 3 lỗi và DB không đổi, zip có 2 `.xlsx` →
  báo lỗi và DB không đổi, zip hợp lệ bọc thư mục → nhập 14 câu + 4 ngữ liệu trong
  1,3 s, ẩn, đúng mục 1, đúng tên, draft đã xoá, gửi lại bị từ chối. Ca thành công
  chạy trong transaction rồi rollback: DB về đúng baseline. Qua HTTP (chưa đăng
  nhập) cả 3 đường vào đều 303 về trang đăng nhập, không fatal.
- **Bẫy mới: `SITEID` là CHUỖI `'1'`** (`lib/setup.php:863` gán từ `$SITE->id`).
  `$courseid === SITEID` luôn sai → form suýt cho chọn trang chủ làm khoá đích.
  Test bắt được. Cùng họ với bẫy "driver trả số dạng chuỗi" — áp dụng cả cho hằng.
- **Moodle 4.5 dùng Bootstrap 4.6.2**: `text-right`, không phải `text-end`.
- Mustache phía PHP **không** hiểu `{{mảng.length}}` như bản JS — truyền số đếm từ PHP.
- **Đính chính** cảnh báo cũ về việc lưu cài đặt quiz làm dồn trang (xem mục 3).

### 2026-09-21 (phiên 3)
- **Sửa thanh 7 Part + số câu trên dashboard.** `get_structure_and_parts()` đếm
  cả 42 khối ngữ liệu → đề hiện "242 câu" và thanh chỉ lấp 82,6%. Nay chỉ đếm
  slot `length > 0` (`description` có `length = 0`), và chỉ hiện thanh khi **mọi**
  câu thật đều có tag `partN` (đúng hợp đồng docblock — code cũ hiện thanh khi
  chỉ cần 1 câu có tag). Đo lại trên đề `#3`: **200 câu, tổng 100%**. Đã thử trong
  transaction rồi rollback: khối ngữ liệu lỡ gắn `part3` → bị bỏ qua; gỡ tag một
  câu → thanh ẩn. Quiz `#1 TEST` không đổi (vẫn ẩn thanh). **Chưa xem bằng mắt**:
  đề `#3` đang ẩn nên học viên chưa thấy nó trên dashboard.
- **Bẫy khi viết test:** `core_tag_tag::remove_all_item_tags()` chỉ xoá tag ở
  **context hệ thống** (`tag/classes/tag.php:871`), mà tag câu hỏi cho phép nhiều
  context → không xoá gì cả, không báo lỗi. Muốn gỡ tag câu hỏi thì truyền đúng
  context vào `set_item_tags()`.

### 2026-09-21 (phiên 2)
- **Viết `importer.php`** + `db/install.xml` + `db/upgrade.php` +
  `cli/import_test.php`, thêm `spec::PART_NAMES`. **Nhập thành công đề `DE_01`
  thật**: 242 slot / 3,5 s. Chi tiết và 4 bẫy đã né ở mục 3.
- **Đã tạo bảng `local_quizportal_slotmeta`** + đăng ký observer (plugin version
  **2026092102**, đã chạy `admin/cli/upgrade.php`). Phiên sau kéo code về thì phải
  chạy upgrade lại.
- Thử đường `.zip` bằng đề demo 14 câu rồi **xoá ngay** (quiz `#4`, `#5` và 2
  category demo không còn). Site hiện có đúng 2 quiz: `#1 TEST` cũ và `#3` đề thật.
- **Đề `#3` đang nằm trong khoá `#2`, trạng thái ẩn.** Đây là đề nhập thử. Muốn
  dùng thật thì bỏ ẩn; muốn xoá thì xoá activity + category `Đề 01 — Practice Test 1`.
- **Thanh 7 Part đã sáng** — mục tiêu chính của B1. Đo được: hụt 17,4% bên phải.

### 2026-09-21
- **Viết `xml_builder.php`** (B1 phần 3/5). Kiểm chứng bằng đề thật `DE_01` +
  file demo 14 câu + 5 đường lỗi + bộ ký tự hiểm (`&`, `<script>`, `]]>`), tất cả
  đạt. Chi tiết ở mục 3.
- **Chốt 3 quyết định sản phẩm** (người dùng chọn, đừng bàn lại): Part 1/2 in
  nhãn `(A) (B) (C) (D)` chứ không tách nghĩa từ transcript; lời thoại Part 3/4
  **ẩn** khi đang thi; transcript + explain lưu ở `<generalfeedback>` chứ không
  phải bảng riêng.
- **Giới hạn upload đã được nâng từ trước** (`phpForApache.ini`, 18/09 09:15):
  64M/64M/256M/300. Mục "chặn số 0" trong `samples/SAU-KHI-NHAP-DE.md` **đã hết
  hiệu lực** — file nghe 44 MB lọt qua 64M. Chỉ cần chắc Apache đã restart.
- **Có file transcript mà ghi chú cũ nói là không có:**
  `D:\Learn\lam_viec\moodle\De_1\Script - ETS 2024 LC.pdf` (200 MB, có từ 8/7).
  Nếu đúng là script của bộ ETS 2024 thì sửa được phần lời thoại Part 2–4 đang
  dính chữ, **và có thể dò ra mốc `audio_start`** đang thiếu 100%.

### 2026-09-18
- **Điền đề `DE_01` câu 42–200** từ 2 PDF scan (không có lớp chữ, không OCR →
  render trang bằng PyMuPDF rồi đọc bằng mắt) + 2 ảnh đáp án. 200/200 đáp án khớp
  nội dung. Sửa kèm: chữ "Đ" ở `test_name`, dấu cách câu 32–40 (chỉ khi bỏ dấu
  cách thì trùng khớp với PDF). Cắt `media/p7_149.png` vì câu 150 dựa vào giờ được gạch chân.
  Có thể chạy trình kiểm tra khi WAMP tắt: script bọc `ABORT_AFTER_CONFIG`.
- Trả lời người nhập liệu: **Directions của từng Part không nhập vào Excel**. Sheet
  `passages` chỉ nhận Part 3/4/6/7, và Directions giống nhau ở mọi đề nên sẽ lưu
  một lần, hiện ở trang làm bài (A6). Mốc `audio_start` của câu 1 lấy lúc đọc
  "Number one", không phải `0:00`. Đã chạy kiểm tra file đang nhập dở (xem mục
  "Còn dang dở").
- **B1 phần 1/2:** chốt hợp đồng dữ liệu `.xlsx`, viết bộ sinh file mẫu + trình
  kiểm tra, kiểm chứng bằng file demo 14 câu đủ 7 Part và một file cố tình gieo
  6 lỗi (bắt đủ 6/6). Viết hướng dẫn số hoá từ PDF.
- Rà soát toàn bộ `local/quizportal`; vá 7 vấn đề A–G (2 lỗi bảo mật ở luồng đăng
  nhập), revert 2 file core bị sửa nhầm. Viết `PROGRESS.md`.
- Tự host Lora + Be Vietnam Pro (15 file woff2, ~250 KB, subset vietnamese +
  latin + latin-ext). Không dùng Google Fonts: phòng thi nội bộ không chắc có internet.
- Viết `moodle-ptedu-roadmap.md` — lộ trình 3 track + chẩn đoán "giao diện gundam".
- **Trang chủ:** canh giữa panel `#topofscroll` theo chiều dọc + thêm nút "Đăng
  nhập" thứ hai ở cuối nội dung (renderer override). **Trang đăng nhập:** đổi
  sang bố cục 2 dòng, gỡ cap 500px của Boost.
- **Track 0 xong:** dựng `theme/ptedu` (child của Boost), bảng màu "Giấy & Mực"
  đạt WCAG AA toàn bộ, chuyển font vào theme, nối `styles.css` của plugin vào
  token `--pt-*`, **gỡ hết 7 `!important`**. Kích hoạt theme, CSS biên dịch OK.
