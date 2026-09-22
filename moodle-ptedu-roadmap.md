# PTEducation — Lộ trình công việc

Mục tiêu:
1. **Học viên** — trang luyện thi TOEIC/ETS có âm thanh, hình ảnh, giao diện đẹp.
2. **Quản trị** — quản lý khoá học / học viên / đề thi minh bạch, ít nhảy trang.

Trạng thái hiện tại: xem `local/quizportal/PROGRESS.md`.
Hệ thống thiết kế hiện tại: xem `moodle-dashboard-design-notes.md`.

Lập ngày 2026-09-18. Trạng thái hiện hành: xem `CLAUDE.md`.

---

## Track 0 — Nền tảng: theme + hệ thống thiết kế  ✅ **XONG 2026-09-18**

> Đã triển khai thành `theme/ptedu`. Chi tiết và các gotcha: xem `CLAUDE.md` mục 3.

Trực giác của bạn đúng: phải làm ngay. Mọi trang xây sau đều thừa hưởng lớp này;
làm sau nghĩa là phải sơn lại tất cả những gì dựng ở giữa.

Ngoài ra còn một lý do kiến trúc: `local/quizportal/styles.css` hiện đang **chống
lại Boost bằng `!important`** (dòng 182, để vô hiệu hoá `.login-container` rộng
500px cố định). Càng thêm trang, càng thêm cuộc chiến specificity. Một child theme
xoá bỏ cuộc chiến đó.

| # | Việc | Ghi chú |
|---|---|---|
| 0.1 | Chốt bảng màu + thang chữ | Đề xuất ở mục "Hướng thiết kế" bên dưới |
| 0.2 | Tạo `theme/ptedu` — **child theme của Boost** | `$THEME->parents = ['boost']`. Boost có sẵn `prescsscallback` / `extrascsscallback` và preset (`theme/boost/scss/preset/`) → ghi đè biến SCSS **trước** khi Bootstrap biên dịch |
| 0.3 | Chuyển token từ `styles.css` vào SCSS của theme | Một nguồn sự thật duy nhất, bỏ toàn bộ `!important` |
| 0.4 | Dựng bộ component: nút, ô nhập, bảng, pill trạng thái, khối nổi, trạng thái rỗng | Định nghĩa một lần, mọi trang sau dùng lại |
| 0.5 | Chuyển `local/quizportal/fonts/` sang theme | Font đã tự host xong, chỉ đổi chỗ |

**Lợi ích kép quan trọng:** child theme restyle **cả trang admin** miễn phí. CSS
hiện tại chỉ chạm được 2 trang của cổng học viên. Đây là lý do Track 0 phục vụ cả
mục tiêu 2, không chỉ mục tiêu 1.

---

## Track A — Trải nghiệm học viên (mục tiêu 1)

### ⚠️ Sự thật cần biết trước: Moodle quiz **không phải** engine TOEIC

Tôi đã kiểm tra mã nguồn 4.5 trong repo này. Bốn khoảng cách thật:

| # | Khoảng cách | Đã kiểm chứng |
|---|---|---|
| **A1** | **Không có loại câu hỏi "nhiều câu chung một ngữ liệu"** (Part 3, 4, 6, 7: một hội thoại/đoạn văn → 3–5 câu hỏi) | Đã liệt kê toàn bộ `question/type/`: `multichoice`, `multianswer`, `gapselect`, `ddwtos`, `match`, `ordering`, `essay`… **không có loại nào nhóm câu hỏi theo ngữ liệu**. `multianswer` (cloze) là một câu hỏi có ô trống nhúng, không phải nhiều câu chấm riêng |
| **A2** | **Audio phát một lần, không tua lại** cho Part 1–4 | Moodle không có khái niệm này. Nhưng `QUIZ_NAVMETHOD_SEQ` ('sequential') **có tồn tại** (`mod/quiz/lib.php:77`) → khoá không cho quay lại câu trước đã có sẵn |
| **A3** | **Thang điểm TOEIC 10–990** (mỗi kỹ năng 5–495, quy đổi theo bảng) | Moodle chấm điểm thô (vd `178/200`). Cần lớp quy đổi riêng |
| **A4** | **Cấu trúc 7 Part** | `quiz_sections` **có sẵn** (`quizid`, `firstslot`, `heading`, `shufflequestions`) → mô hình Part bằng section là hợp lý hơn tag. Dashboard hiện dùng tag; nên dùng **cả hai**: section cho thứ tự + tiêu đề, tag cho phân loại từng câu |

**Hướng xử lý A1** (chọn 1): (a) lặp lại đoạn văn trong đề bài từng câu — xấu
nhưng chạy được ngay; (b) dùng qtype `description` làm khối ngữ liệu + các câu
hỏi ngay sau trong cùng section; (c) viết qtype mới — tốn kém; (d) **renderer
riêng ở trang làm bài, gom câu theo section và ghim ngữ liệu sang một cột**.
Khuyến nghị **(b) + (d)**: dữ liệu vẫn chuẩn Moodle, chỉ trình bày là của mình.

### Các trang cần dựng

| # | Trang | Nội dung chính |
|---|---|---|
| A5 | **Trang chi tiết đề** (trước khi vào làm) | Hướng dẫn, cấu trúc Part, số lượt còn lại, nút bắt đầu |
| A6 | **Trang làm bài** | Điều hướng câu hỏi, ngữ liệu ghim, audio Part 1–4, đồng hồ đếm ngược, lưới đáp án |
| A7 | **Trang xem kết quả** | Điểm quy đổi 10–990, bóc tách theo Part, đáp án đúng/sai, transcript, nghe lại audio |
| A8 | **Trang hồ sơ / lịch sử** | Biểu đồ tiến bộ, điểm mạnh yếu theo Part |

---

## Track B — Trải nghiệm quản trị (mục tiêu 2)

Điểm đau thật của Moodle gốc với quy trình này:

- Mở một lớp = tạo course → ghi danh → tạo quiz → thêm câu hỏi từ bank → đặt lịch. **5+ màn hình.**
- Ghi danh hàng loạt = *Site admin → Users → Upload users*, **tách rời** khỏi khoá học.
- Nhập 200 câu kèm audio/ảnh = import question bank + upload file riêng.

| # | Việc | Vì sao đáng làm |
|---|---|---|
| **B1** | **Trình nhập đề TOEIC hàng loạt** — ZIP (audio + ảnh) + CSV/XLSX 200 câu → tự tạo quiz, tạo section theo Part, gắn tag `part1..part7`, đính kèm media | **Đòn bẩy cao nhất.** Vừa xoá điểm đau lớn nhất, vừa **mở khoá thanh 7 Part** đang bị ẩn vì thiếu tag (xem PROGRESS.md mục 2) |
| **B2** | **Wizard "Tạo lớp"** một trang: tên lớp → dán email/CSV học viên → chọn bộ đề → đặt lịch → submit | Gộp 5+ màn hình thành 1 |
| **B3** | **Bảng điều khiển lớp** — lưới học viên × đề: ai đã làm, điểm bao nhiêu, ai chưa làm | Thay cho việc lần mò trong Reports |
| **B4** | **Quản lý học viên** một trang — thêm/xoá, reset mật khẩu, chuyển lớp | |
| **B5** | Giao diện admin | Xong tự động khi Track 0 hoàn tất |

---

## Track C — Kỹ thuật & vận hành

| # | Việc |
|---|---|
| C1 | 4 mục còn phải test thủ công trong `PROGRESS.md` mục 5 (cờ `confirmed`, cookie ghi nhớ, `wantsurl`, không hồi quy dashboard) |
| C2 | Tách chuỗi sang `lang/vi` |
| C3 | `login.php` → Mustache template + renderer (hiện dùng `html_writer`, lệch kiến trúc với dashboard) |
| C4 | **Dung lượng media** — 200 file audio × N đề. Tính trước chỗ lưu, sao lưu, và tốc độ tải trong phòng thi |
| C5 | Sao lưu / phục hồi `moodledata` + database |
| C6 | Dọn nợ kỹ thuật còn lại trong `PROGRESS.md` mục 4 |

---

## Thứ tự khuyến nghị

1. **Track 0** — theme + design system. Nền móng, và bạn đã nhận ra đúng.
2. **B1** — trình nhập đề. Không có đề thật 200 câu kèm audio thì **không có gì để
   thiết kế trang làm bài dựa trên đó**. Đồng thời mở khoá thanh 7 Part.
3. **A6 + A2** — trang làm bài + cơ chế audio phát một lần.
4. **A7 + A3** — trang kết quả + quy đổi điểm 10–990.
5. **B2 + B3** — bảng điều khiển giáo viên.
6. **Track C** — dọn dẹp.

Lý do B1 đứng trước mọi trang học viên: thiết kế trang làm bài bằng dữ liệu giả
sẽ phải làm lại khi gặp đề thật — đoạn văn dài hơn tưởng, audio khác định dạng,
ảnh Part 1 khác tỉ lệ.

---

## Hướng thiết kế đề xuất

### Chẩn đoán: vì sao đang thấy "gundam"

Bạn nhận xét đúng, và lý do cụ thể hơn là "xanh + đỏ":

1. **Navy `#1B2A4A` + đỏ `#C1272D` đều bão hoà cao** → hai màu chính tranh nhau,
   không có tông trung gian hoà giải. Đó đúng là công thức bảng màu cờ / mecha.
2. **Đỏ bão hoà là màu cảnh báo theo quy ước UI.** Dùng nó cho nút hành động
   chính khiến mọi CTA đọc như một lời cảnh báo — mệt mắt trong buổi học 2 tiếng.
3. **Vấn đề chức năng, không phải thẩm mỹ:** trong ứng dụng thi, **đỏ = sai** và
   **xanh lá = đúng** là ngữ nghĩa bắt buộc. Tiêu đỏ cho nút bấm nghĩa là khi dựng
   trang xem kết quả (A7), bạn **không còn màu nào để đánh dấu câu sai**. Đây là
   lý do mạnh nhất để đổi, và nó sẽ cắn vào đúng màn hình kế tiếp.
4. **Nền `#EEF1F4` là xám lạnh** → đọc ra "phần mềm". Đề thi giấy thật có tông ấm.
5. **`border-radius: 0` tuyệt đối** đọc ra brutalist chứ không ra "giấy". Giấy
   thật không sắc như dao.

### Điều đang tốt, **giữ nguyên**

Thành thật: *cấu trúc* bạn dựng không hề "AI". Những thứ sau là tín hiệu
người-thiết-kế thật, đừng bỏ:

- Ẩn dụ vé/phiếu báo thi và bảng lịch thi.
- **Thanh 7 Part** — chi tiết chỉ người hiểu TOEIC mới nghĩ ra. Đây là thứ chống
  "AI-generated" mạnh nhất trên trang.
- Danh sách dạng bảng dày thay vì lưới card — hợp bối cảnh và tránh lặp pattern.
- Quy ước `dt/dd` dùng lại giữa hai trang.

→ Vậy đây là **sửa bảng màu + chất liệu**, không phải làm lại. Tin tốt, đỡ việc.

### Đề xuất: "Giấy & Mực"

Giữ ẩn dụ giấy, đổi tông từ lạnh sang ấm, đổi điểm nhấn từ đỏ sang **đất nung**.

| Token | Giá trị | Vai trò | Tương phản |
|---|---|---|---|
| `--paper` | `#F7F5F0` | Nền trang, tông giấy **ấm** | — |
| `--sheet` | `#FFFDF9` | Khối nổi, trắng ấm | — |
| `--ink` | `#14303A` | Chữ chính, tiêu đề, thương hiệu — xanh dầu hoả sâu | 12.73 AAA |
| `--ink-soft` | `#46626E` | Chữ phụ | 5.96 AA |
| `--ink-faint` | `#5F727C` | Nhãn nhỏ, meta | 4.61 AA |
| `--line` | `#DED8CD` | Viền, đường kẻ — **ấm**, ăn với nền giấy | hairline |
| `--accent` | `#A75D1B` | **Điểm nhấn duy nhất**: nút chính, trạng thái "đang mở" | 4.56 AA · chữ trắng trên nền 4.97 AA |
| `--correct` | `#2E7D52` | **Dành riêng**: câu đúng | 4.62 AA |
| `--wrong` | `#B3261E` | **Dành riêng**: câu sai | 6.00 AA |
| `--ink-disabled` | `#8496A0` | Chỉ cho control vô hiệu hoá (WCAG miễn trừ) | 2.81 |

Toàn bộ đã kiểm tra bằng công thức tương phản WCAG — **không giá trị nào trượt AA**.

**Luật màu:** `--accent` chỉ xuất hiện ở nút hành động chính và trạng thái "đang
mở". `--correct` / `--wrong` **không bao giờ** dùng cho khung sườn giao diện —
giữ nguyên vẹn cho trang kết quả.

### Vì sao hướng này "đỡ AI" hơn

Những thứ khiến giao diện bị đọc ra là máy sinh:

| Dấu hiệu AI | Cách né ở đây |
|---|---|
| Nền xám lạnh / slate | Nền **giấy ấm** `#F7F5F0` |
| Tím, chàm, gradient | **Đất nung** `#A75D1B`, phẳng, không gradient |
| `border-radius` 12px đồng loạt trên mọi card | **2px** ở phần tử nhỏ, **3px** ở khối nổi — đủ bớt gắt, chưa thành card SaaS |
| Lưới 3 cột card cân bằng tuyệt đối | Danh sách dạng bảng, **có nhịp dày/thưa** |
| Icon + tiêu đề + mô tả lặp lại | Chi tiết theo miền: thanh 7 Part, `lượt làm 1/2`, biểu tượng audio |
| Emoji trong tiêu đề | Không dùng |

Chữ: **giữ Lora + Be Vietnam Pro** — lựa chọn tốt sẵn, và Be Vietnam Pro hỗ trợ
dấu tiếng Việt đầy đủ (đã tự host, subset vietnamese). Việc cần thêm là một
**thang chữ thật** (hiện các cỡ khá gần nhau), và dùng Lora **thưa thôi** — chỉ
tiêu đề và điểm số.

Chất liệu: trang login đã có lưới chấm mờ trên nền navy — giữ ý tưởng đó nhưng
chuyển sang nền giấy: kẻ ngang rất mờ hoặc vân sợi giấy.

### Hai hướng thay thế

- **"Thư viện"** — kem + xanh rêu sâu + đồng thau. Học thuật kiểu Cambridge, uy
  tín hơn, nhưng dễ thành cổ kính nếu quá tay.
- **"Tối giản ấm"** — trung tính ấm + một sắc chàm dịu. An toàn nhất, nhưng **rủi
  ro cao nhất là bị đọc ra giao diện SaaS chung chung** — tức đúng thứ bạn muốn né.
