# Test trang làm bài và trang kết quả bằng Chrome thật

Bốn bước, chạy nối tiếp trên một khoá học thử riêng:

| Bước | File | Kiểm | Số phép kiểm |
|---|---|---|---|
| 1 | `exam_test.js` | Một lượt làm trọn vẹn trên `attempt.php`: bấm nghe, nghe tiếp sau khi tải lại, tạm dừng, tua lén, tự chuyển câu theo mốc âm thanh, hết băng sang trang chuyển tiếp, phần đọc, phiếu trả lời, nộp bài → trang kết quả. **Cờ đánh dấu**: cắm cờ câu 1 và 32 lúc đang nghe (vào CSDL, còn sau khi tải lại), trang chuyển tiếp liệt kê, cờ trên phiếu trả lời phần đọc, nút "Tới câu đánh dấu" vòng lại từ đầu, bỏ cờ, hộp nộp bài nhắc câu còn cờ, bộ lọc "Đã đánh dấu" ở trang kết quả | 66 |
| 2 | `score_check.php` | Chấm điểm (CLI): bảng mặc định nằm trong mọi khoảng Oxford in, một lượt làm biết trước kết quả (Nghe 79 đúng, Đọc 63 đúng → **700**) chấm theo 3 đường cho cùng một số, đổi bảng áp dụng ngay, trang kết quả render đủ | 22 |
| 3 | `result_test.js` | Trang kết quả `review.php` trên lượt làm của bước 2: phiếu điểm, bảng Part, lọc câu sai, phiếu trả lời tô màu, nghe lại tự dừng đúng mốc, link `#cau-N`, dashboard hiện 700 / 990 | 27 |
| 4 | `scale_test.js` | Trang sửa bảng quy đổi `scale.php`: trình duyệt chặn giá trị sai bước/sai khoảng, server chặn bảng giảm dần, sửa một ô → điểm cũ đổi ngay 700 → 705, khôi phục Oxford | 14 |

Ảnh chụp từng bước nằm trong `shots/`.

Test **dời đồng hồ phần nghe trên server** để tới câu 1, Part 3 và cuối băng mà
không phải chờ 46 phút, và **tạm cho tài khoản giáo viên thử làm site admin**
trong bước 4. Vì vậy nó chỉ chạy trên khoá học thử do `fixture.php` dựng và xoá —
không bao giờ trỏ vào khoá có học viên thật.

## Cài một lần

```bash
cd local/quizportal/tools/exam_e2e
npm install
```

Chỉ cài `puppeteer-core` (không tải Chrome) — dùng Chrome có sẵn trên máy.
Đường dẫn Chrome, PHP và địa chỉ site đổi được bằng biến môi trường `CHROME`,
`PHP`, `WWWROOT`.

## Chạy

WAMP phải đang chạy. Từ thư mục gốc Moodle:

```bash
PHP="/c/wamp64/bin/php/php8.1.33/php.exe -d max_input_vars=5000"
E=local/quizportal/tools/exam_e2e

$PHP $E/fixture.php setup                  # -> course 9 quiz 16 cmid 24 slots 242
(cd $E && CMID=24 node exam_test.js)
$PHP $E/score_check.php --keep             # -> ... attempt 29
(cd $E && ATTEMPT=29 node result_test.js)
(cd $E && ATTEMPT=29 node scale_test.js)
$PHP $E/fixture.php teardown
```

`setup` tạo khoá `qptest`, hai tài khoản `qp_student` / `qp_teacher` (mật khẩu
cố định, xem `fixture.php`) và nhập lại đề từ `--dir` (mặc định
`D:/Learn/lam_viec/moodle/De_1`). `score_check.php` không có `--keep` thì tự xoá
lượt làm của nó.

`teardown` gỡ quyền admin của tài khoản thử (nếu bước 4 lỡ dừng giữa chừng),
xoá khoá, xoá **cả bản sao trong thùng rác danh mục** (site bật thùng rác,
`delete_course()` tự lưu một bản), xoá hai tài khoản và **báo lỗi to nếu không
xoá được** — `delete_user()` âm thầm từ chối xoá một site admin. **Luôn chạy
teardown**: hai tài khoản dùng chung một mật khẩu ai cũng đọc được.

Sau teardown, kiểm tra: `local_quizportal_slotmeta` chỉ còn dòng của đề thật,
`local_quizportal_attemptstate` không còn dòng nào của khoá thử, `$CFG->siteadmins`
như trước, bảng quy đổi không bị sửa (bước 4 tự khôi phục Oxford).

## Không kiểm được

- Âm thanh có thật sự ra loa hay không (Chrome chạy `--mute-audio`) — chỉ kiểm
  được `currentTime` chạy đúng.
- Safari và Firefox. Chính sách tự phát âm thanh của hai trình duyệt này khác
  Chrome; trang đã tính đến (gọi `play()` ngay trong cú bấm), nhưng chưa ai thử.
