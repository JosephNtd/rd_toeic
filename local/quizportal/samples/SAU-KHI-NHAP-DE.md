# Sau khi nhập đề — danh sách việc còn lại

Kiểm kê ngày 2026-09-18 trên chính máy này, không phải danh sách chung chung.

Hiện trạng đo được:

| | |
|---|---|
| Khoá học | `#2 TOEIC` — 2 người: `hocvien1` (student), `admin` (editingteacher) |
| Quiz | `#1 TEST` — 5 câu, timelimit 5 giờ, 1 lượt, nav=free, grade=10 |
| Ngân hàng câu hỏi | 3.743 câu, nhưng **3.738 câu là dữ liệu mẫu của Moodle** (`TSL 1.2 MC … Distractors`). Đề thật chỉ có 5 câu |
| Tag `part1..part7` | Chưa có câu nào → thanh 7 Part trên dashboard đang bị ẩn |
| Dung lượng file | 7,7 MB |

---

## 0. Chặn ngay — phải sửa trước khi nhập được đề thật

**Giới hạn upload quá nhỏ so với file nghe.**

`C:\wamp64\bin\php\php8.1.33\phpForApache.ini` (đây là file Apache dùng; file
`php.ini` cùng thư mục chỉ dành cho CLI, sửa nhầm file là không có tác dụng):

| Thiết lập | Hiện tại | Nên đặt | Vì sao |
|---|---|---|---|
| `upload_max_filesize` | 20M | **64M** | File nghe 45 phút: ~11 MB ở 32 kbps, ~22 MB ở 64 kbps, ~43 MB ở 128 kbps. Cộng thêm ảnh Part 1 và Part 7 |
| `post_max_size` | 20M | **64M** | Phải ≥ upload_max_filesize, nếu không upload vẫn bị chặn |
| `memory_limit` | 128M | **256M** | Nhúng media vào XML làm phình ~33% và cả chuỗi nằm trong RAM |
| `max_execution_time` | 120 | **300** | Nhập 200 câu kèm media dễ vượt 2 phút |

Sửa xong phải **khởi động lại Apache**, không thì không ăn.

> Nếu file nghe của bạn ở 32 kbps mono thì 20M hiện tại có thể vừa đủ. Nhưng đừng
> cắt chất lượng âm thanh chỉ để lách giới hạn — nghe là kỹ năng đang được chấm.

---

## 1. Ngay sau khi nhập — kiểm tra và tinh chỉnh

- [ ] **Tắt xáo trộn câu hỏi.** `quiz_sections.shufflequestions` phải = 0 cho
      Part 3, 4, 6, 7. Xáo câu sẽ tách rời nhóm câu khỏi ngữ liệu chung **và**
      phá thứ tự mốc audio. Đây là thứ dễ quên nhất và hỏng nặng nhất.
- [ ] **Đặt lại thiết lập quiz.** Quiz `TEST` đang là 5 giờ / grade 10 — rõ ràng
      là đồ thử nghiệm. Đề thật: 120 phút, grade theo số câu.
- [ ] **Kiểm tra thanh 7 Part đã hiện** trên dashboard học viên. Nhập xong là có
      tag, thanh phải sáng lên. Nếu vẫn ẩn tức là tag chưa gắn được.
- [ ] **Đặt `timeopen` / `timeclose`** theo lịch lớp.
- [ ] **Mở thử một câu mỗi Part** xem ảnh và ngữ liệu hiện đúng chưa.

## 2. Còn thiếu để thành một kỳ thi TOEIC thật

- [ ] **Trang làm bài riêng** — audio phát một lần, đồng hồ đếm ngược, ngữ liệu
      ghim bên cạnh câu hỏi *(mục A6, A2 trong roadmap)*.
- [ ] **Quy đổi điểm 10–990** — Moodle chấm thô `178/200` *(mục A3)*.
- [ ] **Trang xem kết quả** — bóc tách theo Part, transcript, nghe lại *(mục A7)*.

### ⚠️ Một giới hạn của Moodle cần quyết sớm

`navmethod` là **cột trên bảng `quiz`**, còn `quiz_sections` chỉ có
`id, quizid, firstslot, heading, shufflequestions` — **không có cột điều hướng
riêng cho từng section**.

Nghĩa là Moodle **không thể** vừa khoá Listening (không cho quay lại) vừa để
Reading tự do trong **cùng một quiz**. Ba đường:

| Cách | Được | Mất |
|---|---|---|
| Tách thành 2 quiz (Listening + Reading) | Dùng đúng cơ chế sẵn có của Moodle, không phải viết gì | Không còn là "một đề 200 câu"; điểm phải cộng thủ công từ 2 quiz |
| Tự cưỡng chế trong trang làm bài riêng | Giữ một đề duy nhất, đúng trải nghiệm phòng thi | Phải tự viết, và chỉ chặn được ở phía trình duyệt |
| Chấp nhận tự do cả bài | Không tốn gì | Không giống thi thật — học viên tua lại phần nghe |

Tôi nghiêng về **cách 2**, vì đằng nào cũng phải viết trang làm bài riêng cho
audio phát một lần.

## 3. Vận hành mỗi lớp *(hiện phải làm tay — đây chính là mục B2, B3)*

- [ ] Tạo lớp, ghi danh học viên
- [ ] Giao đề cho lớp, đặt lịch mở/đóng
- [ ] Theo dõi ai đã làm / chưa làm, xuất điểm

## 4. Dọn dẹp và hạ tầng

- [ ] **Xoá 3.738 câu hỏi mẫu của Moodle** (`TSL 1.2 MC … Distractors`). Đó là
      dữ liệu test do Moodle sinh ra, không phải đề của bạn. Để lại sẽ làm ngân
      hàng câu hỏi rối và tìm kiếm chậm dần.
- [ ] **Tính trước dung lượng.** Mỗi đề ~25 MB audio. 20 đề ≈ 500 MB, chưa kể
      ảnh và bản sao lưu. Hiện mới dùng 7,7 MB.
- [ ] **Sao lưu `moodledata` + database.** Công sức nhập liệu nằm hết ở đó.
- [ ] **Băng thông phòng thi.** 30 học viên cùng tải một file 22 MB là 660 MB
      trong vài phút. Nếu thi tại chỗ, cân nhắc cho tải trước.

## 5. Kiểm thử còn treo

- [ ] 4 mục cần tài khoản học viên thật — xem `PROGRESS.md` mục 5.
- [ ] Chạy thử toàn tuyến bằng `hocvien1`: đăng nhập cổng → thấy đề → làm bài →
      xem kết quả.
