# Trang danh sách bài thi — Ghi chú thiết kế

File giao diện: `moodle-dashboard.html`
Trang trước đó (cùng hệ thống thiết kế): `moodle-login.html`

Đây là trang học viên thấy sau khi đăng nhập — danh sách các đề thi TOEIC mô
phỏng chuẩn ETS (200 câu, Listening & Reading, có âm thanh và hình ảnh) mà học
viên trong lớp được giao làm.

## 1. Hệ thống thiết kế (dùng chung với trang đăng nhập)

Ý tưởng chủ đạo: giao diện được thiết kế như một **vé/phiếu báo thi và bảng
lịch thi** thật, thay vì các "card" bo góc kiểu SaaS thông thường — vì đối
tượng là học viên chuẩn bị thi TOEIC, sự liên tưởng tới không khí phòng thi
giúp giao diện gắn với đúng bối cảnh.

| Token | Giá trị | Vai trò |
|---|---|---|
| `--paper` | `#EEF1F4` | Nền trang, tông giấy lạnh |
| `--sheet` | `#FFFFFF` | Nền các khối nổi (thanh thống kê) |
| `--ink` | `#1B2A4A` | Màu chữ chính, header, viền badge |
| `--ink-soft` | `#5B6B85` | Chữ phụ, mô tả |
| `--ink-faint` | `#8B96AA` | Chữ mờ, trạng thái vô hiệu hoá |
| `--line` | `#C7D0DC` | Viền, đường phân cách |
| `--red` | `#C1272D` | Điểm nhấn duy nhất: trạng thái "đang mở", nút hành động chính |
| `--red-dark` | `#971E23` | Hover của nút chính |

Font: **Lora** (serif) cho tiêu đề/điểm số — tạo cảm giác "văn bản chính thức";
**Be Vietnam Pro** (sans, hỗ trợ dấu tiếng Việt đầy đủ) cho phần còn lại.

Quy tắc màu đỏ: **chỉ** xuất hiện ở trạng thái "Đang mở" và nút hành động
chính. Không dùng đỏ cho badge số thứ tự hay trạng thái khác — giữ cho màu đỏ
luôn có nghĩa là "cần hành động ngay", tránh loãng điểm nhấn.

Không bo góc (`border-radius: 0`) xuyên suốt — tiếp tục ẩn dụ "giấy in/vé
giấy" từ trang đăng nhập, đồng thời tránh kiểu "card bo góc + đổ bóng giống
nhau" vốn là mặc định của giao diện AI-generated.

## 2. Cấu trúc trang

```
┌─ header (nền navy) ───────────────────────────────┐
│ PT PTEducation | Lớp: Cơ bản K09      NA Tên  Đăng xuất │
├────────────────────────────────────────────────────┤
│ H1: Danh sách bài thi                                │
│ mô tả ngắn về định dạng đề (200 câu, 120 phút...)   │
│                                                       │
│ [ Tổng số đề | Đã hoàn thành | Điểm trung bình ]     │  ← thanh thống kê
│                                                       │
│ Tất cả · Đang mở · Đã hoàn thành · Sắp mở            │  ← tab lọc
│ ■ Nghe (Part 1–4)   ■ Đọc (Part 5–7)                 │  ← chú giải
│ ──────────────────────────────────────────────────  │
│ 01 │ Đề 01 — ETS 2024 Test 1        │ KẾT QUẢ │ Xem  │  ← mỗi dòng = 1 đề
│    │ 200 câu · 120 phút · 🎧 · 🖼    │ 785/990 │ kết  │
│    │ ▓▓▓▓▓▓▓▓░░░ (thanh 7 phần)     │         │ quả  │
│ ──────────────────────────────────────────────────  │
│ 02 │ ...                                             │
└────────────────────────────────────────────────────┘
```

Danh sách đề thi được trình bày như **bảng lịch thi / danh sách phòng thi**
(đường kẻ ngang phân cách, không có khung/đổ bóng riêng từng dòng) thay vì
lưới card — vừa hợp bối cảnh, vừa tránh lặp lại một pattern giống hệt nhau
nhiều lần trên trang.

## 3. Thành phần chi tiết

### Thanh 7 phần (`.partbar`)

Mỗi dòng đề thi có một thanh ngang chia theo đúng cấu trúc thật của đề TOEIC
Listening & Reading (200 câu):

| Part | Nội dung | Số câu | Tỉ lệ |
|---|---|---|---|
| 1 | Photographs | 6 | 3% |
| 2 | Question-Response | 25 | 12.5% |
| 3 | Conversations | 39 | 19.5% |
| 4 | Talks | 30 | 15% |
| 5 | Incomplete Sentences | 30 | 15% |
| 6 | Text Completion | 16 | 8% |
| 7 | Reading Comprehension | 54 | 27% |

4 phần đầu (Nghe) tô bằng `--ink` với độ mờ tăng dần, 3 phần sau (Đọc) tô bằng
`--red` với độ mờ tăng dần — giúp phân biệt Nghe/Đọc mà không cần thêm màu
ngoài bảng màu gốc. `title` attribute hiện số câu từng phần khi hover; có kèm
một dòng `.visually-hidden` tương đương cho trình đọc màn hình.

**Lưu ý khi nối dữ liệu thật:** Moodle không có sẵn khái niệm "7 phần trong 1
quiz". Muốn thanh này phản ánh đúng đề thật, cần gắn tag (ví dụ `part1`…
`part7`) cho từng câu hỏi trong Question Bank, rồi đếm số câu theo tag qua
`mdl_tag_instance` (hoặc field tuỳ biến trên `mdl_question`) khi render trang.
Nếu chưa có dữ liệu tag, có thể tạm ẩn thanh này hoặc để tỉ lệ mặc định như
trên.

### Trạng thái mỗi đề (`data-status`)

| Trạng thái | Điều kiện tương ứng bên Moodle | Hiển thị |
|---|---|---|
| `completed` | Học viên đã có `attempt` với `state = finished` | Điểm số (`sumgrades`/`grademax`), nút "Xem kết quả" + "Làm lại" (nếu còn lượt) |
| `open` | `timeopen <= now <= timeclose` (hoặc không giới hạn), còn lượt làm | Hạn nộp (`timeclose`), nút đỏ "Vào làm bài" |
| `upcoming` | `now < timeopen` | Giờ mở (`timeopen`), nút vô hiệu hoá "Chưa mở" |
| `closed` | `now > timeclose` và chưa có attempt nào hoàn thành | Ngày đóng, nút vô hiệu hoá "Đã đóng" |

Số lượt làm hiển thị dạng `đã làm/tổng cho phép`, tương ứng
`quiz.attempts` (0 = không giới hạn) và số bản ghi trong `mdl_quiz_attempts`
của học viên.

### Tab lọc

4 nút `<button data-filter="all|open|completed|upcoming">`, lọc bằng JS theo
`data-status` của từng dòng (xem `<script>` cuối file `moodle-dashboard.html`).
Trạng thái `closed` chỉ hiện trong tab "Tất cả", không có tab riêng — giữ danh
sách gọn, phù hợp cách Moodle thường không nhấn mạnh các quiz đã đóng và chưa
làm.

### Thanh thống kê đầu trang

Tái dùng đúng kiểu chữ `dt/dd` (nhãn nhỏ in hoa + số liệu serif) đã dùng ở
khối "vé" trên trang đăng nhập — chủ đích lặp lại một quy ước hiển thị đã có,
để hai trang cảm giác cùng một hệ thống chứ không phải hai trang rời rạc.

## 4. Gợi ý cấu trúc dữ liệu (JSON) khi nối API/backend thật

```json
{
  "id": 2,
  "title": "Đề 02 — ETS 2024 Test 2",
  "total_questions": 200,
  "duration_minutes": 120,
  "has_audio": true,
  "has_image": true,
  "parts": [6, 25, 39, 30, 30, 16, 54],
  "status": "open",
  "timeopen": "2026-09-18T00:00:00+07:00",
  "timeclose": "2026-09-25T23:59:00+07:00",
  "attempts_allowed": 2,
  "attempts_used": 0,
  "best_score": null,
  "max_score": 990
}
```

Các field `timeopen`/`timeclose`/`attempts_allowed`/`max_score` map trực tiếp
tới các cột tương ứng trong bảng `mdl_quiz`; `attempts_used`/`best_score` lấy
từ `mdl_quiz_attempts` và `mdl_quiz_grades` theo `userid` hiện tại.

## 5. Việc còn cần làm khi tích hợp vào theme Boost

- Trang này hiện là HTML/CSS/JS tĩnh, dữ liệu 5 đề trong file là dữ liệu mẫu
  để xem giao diện — cần vòng lặp Mustache (`{{# quizzes }}...{{/ quizzes}}`)
  thay cho từng `<li>` viết tay khi đưa vào theme.
- Phần trạng thái/nút hành động nên tính từ `quiz.timeopen`, `quiz.timeclose`,
  `attemptsavailable` — Moodle đã có các hàm helper này trong
  `mod/quiz/classes/output/renderer.php`, không cần tự viết lại logic so sánh
  thời gian.
- Nút "Vào làm bài" nên trỏ tới `mod/quiz/view.php?id={cmid}`, nút "Xem kết
  quả" trỏ tới `mod/quiz/review.php?attempt={attemptid}`.
