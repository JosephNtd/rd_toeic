# Test trang làm bài, trang kết quả, bảng lớp, sao lưu đề, tạo lớp và quản lý học viên

Mười hai bước, chạy nối tiếp trên một khoá học thử riêng. Lần chạy sạch gần nhất của
11 bước cũ: 312/312. Bước `5e` (mới 2026-09-23) đã chạy sạch **39/39 riêng**, hai lần
liên tiếp, và `class_check.php` vẫn chạy được ngay sau nó — nhưng **chưa ai chạy trọn
cả 12 bước một lượt** kể từ khi thêm nó. Trọn bộ nay là 351.
`teardown` xoá mọi khoá `qptest` / `qptest_*` và mọi tài khoản `qp_*` (kể cả tài
khoản mà bước tạo lớp tạo ra, tên đăng nhập là email `qp_...@example.invalid`):

| Bước | File | Kiểm | Số phép kiểm |
|---|---|---|---|
| 1 | `exam_test.js` | Một lượt làm trọn vẹn trên `attempt.php`: bấm nghe, nghe tiếp sau khi tải lại, tạm dừng, tua lén, tự chuyển câu theo mốc âm thanh, hết băng sang trang chuyển tiếp, phần đọc, phiếu trả lời, nộp bài → trang kết quả. **Cờ đánh dấu**: cắm cờ câu 1 và 32 lúc đang nghe (vào CSDL, còn sau khi tải lại), trang chuyển tiếp liệt kê, cờ trên phiếu trả lời phần đọc, nút "Tới câu đánh dấu" vòng lại từ đầu, bỏ cờ, hộp nộp bài nhắc câu còn cờ, bộ lọc "Đã đánh dấu" ở trang kết quả | 66 |
| 2 | `score_check.php` | Chấm điểm (CLI): bảng mặc định nằm trong mọi khoảng Oxford in, một lượt làm biết trước kết quả (Nghe 79 đúng, Đọc 63 đúng → **700**) chấm theo 3 đường cho cùng một số, đổi bảng áp dụng ngay, trang kết quả render đủ | 22 |
| 3 | `result_test.js` | Trang kết quả `review.php` trên lượt làm của bước 2: phiếu điểm, bảng Part, lọc câu sai, phiếu trả lời tô màu, nghe lại tự dừng đúng mốc, link `#cau-N`, dashboard hiện 700 / 990 | 27 |
| 4 | `scale_test.js` | Trang sửa bảng quy đổi `scale.php`: trình duyệt chặn giá trị sai bước/sai khoảng, server chặn bảng giảm dần, sửa một ô → điểm cũ đổi ngay 700 → 705, khôi phục Oxford | 14 |
| 5 | `class_check.php` | Bảng điều khiển lớp (CLI): 5 học viên `qp_hv1-5` với kết quả biết trước — An 700 rồi 290 (lấy 700, yếu Part 4 = 30%), Bình 720 + đang làm lượt mới (yếu Part 7 = 0%), Chi đang làm, Dũng bỏ dở, Ê chưa làm. Giáo viên không nằm trong bảng, lọc theo nhóm, quyền, trang render, file Excel 16 cột | 27 |
| 5b | `backup_check.php` | Sao lưu/khôi phục đề (CLI), cả 3 đường core chép một quiz: **Duplicate**, **xoá rồi lấy lại từ thùng rác khoá học**, **sao lưu cả khoá có học viên → khôi phục thành khoá mới**. Mỗi đường: đủ 242 dòng `slotmeta`, đúng câu hỏi, cùng file nghe (so mã băm), và **chạy được** theo đúng phép kiểm của trang làm bài; trạng thái phần nghe đi theo lượt làm. Tự dọn khoá khôi phục + bản sao trong thùng rác | 20 |
| 5c | `newclass_check.php` | Trình tạo lớp (CLI): đọc danh sách dán vào (tab/phẩy/chấm phẩy, dòng tiêu đề, Unicode tách dấu, "Hà" + dấu cách không ngắt, 9 kiểu dòng sai kèm số dòng), tạo lớp thật (2 tài khoản mới + 1 cũ, giáo viên, đề kèm lịch mở/đóng + sự kiện lịch), đăng nhập bằng email + ngày sinh, lớp hỏng giữa chừng không để lại gì | 30 |
| 5d | `students_check.php` | Quản lý học viên (CLI): trường hồ sơ "Ngày sinh" (khoá, chỉ học viên + admin thấy), lớp mới lưu ngày sinh; **thêm học viên** vào lớp có sẵn (mới / đã có tài khoản / đã trong lớp), danh sách hỏng giữa chừng không để lại tài khoản nào; **đặt lại mật khẩu** về ngày sinh đang lưu hoặc ngày gõ vào (và lưu lại), xoá bộ đếm đăng nhập sai, từ chối admin / người ngoài lớp / tài khoản không dùng mật khẩu; **chuyển lớp**: ghi danh lớp cũ bị đình chỉ chứ không xoá, lượt làm còn nguyên, dashboard và bảng lớp đổi theo, chuyển ngược thì bật lại đúng ghi danh cũ; dashboard ghi tên lớp trên thẻ khi học 2 lớp. Tự trả `qp_hv2`, `qp_hv4` về như cũ | 40 |
| 5e | `recovery_check.php` | Cứu hộ sau sự cố server (CLI), chạy chính `cli/exam_recovery.php` như một tiến trình riêng: 6 lượt ở 6 trạng thái (đang nghe · bị tự đóng trong lúc sự cố · bị đóng quá xa · đã sang phần đọc · chưa mở trang · chưa bấm phát). Xem trước không ghi gì; `--apply` lùi băng đúng số giây và cộng đồng hồ quiz cho **mọi** lượt; thiếu `--reopen` thì không đụng lượt đã đóng; có `--reopen` thì kéo lại đúng lượt còn cứu được và **từ chối** lượt đã sang phần đọc; `--no-quiztime` chỉ lùi băng. 6 đường lỗi. Tự xoá 6 lượt của mình khi xong | 39 |
| 6 | `class_test.js` | Trang `classboard.php` trong Chrome: link trong menu "Xem thêm" của khoá, từng trạng thái, dòng "Cả lớp", sắp xếp 3 cột (chưa làm luôn ở cuối), bấm điểm mở đúng lượt cao nhất, chọn nhóm, tải Excel, 390px, học viên bị từ chối, trang "Các lớp luyện TOEIC" (admin) | 22 |
| 7 | `newclass_test.js` | Trang `newclass.php` trong Chrome: dòng sai báo đúng số dòng, "Kiểm tra trước" không tạo gì và giữ nguyên form, "Tạo lớp" → trang kết quả có danh sách tài khoản để in, tải lại không tạo lần hai, bảng lớp tên "Họ Tên", học viên mới đăng nhập bằng email + ngày sinh thấy đề, học viên cũ đăng nhập bằng email + mật khẩu cũ và thấy **tên lớp trên từng thẻ đề**, sai ngày sinh bị từ chối | 14 |
| 8 | `students_test.js` | Trang `students.php` trong Chrome: 3 lối vào (danh sách lớp, menu khoá học, "Các lớp luyện TOEIC"), danh sách không có giáo viên, thêm học viên (dòng sai, xem trước, thêm, bảng tài khoản để in, tải lại không thêm lần hai), đặt lại mật khẩu cho tài khoản chưa có ngày sinh rồi đăng nhập bằng mật khẩu mới, huỷ, chuyển lớp sang lớp thử thứ hai (`fixture.php second-class`) và thêm lại ("quay lại lớp"), dashboard của học viên 2 lớp ghi tên lớp trên từng thẻ (cả ở 390px), học viên 1 lớp thì không, không tràn ngang ở 390px | 30 |

Ảnh chụp từng bước nằm trong `shots/`.

Test **dời đồng hồ phần nghe trên server** để tới câu 1, Part 3 và cuối băng mà
không phải chờ 46 phút, và **tạm cho tài khoản giáo viên thử làm site admin**
trong bước 4, 6, 7 và 8. Vì vậy nó chỉ chạy trên khoá học thử do `fixture.php` dựng và xoá —
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
$PHP $E/class_check.php                    # -> ... course 9 group 3 an 30
$PHP $E/backup_check.php
$PHP $E/newclass_check.php
$PHP $E/students_check.php
$PHP $E/recovery_check.php                 # tự dọn, chạy chỗ nào trong chuỗi cũng được
(cd $E && ATTEMPT=29 node result_test.js)
(cd $E && ATTEMPT=29 node scale_test.js)
(cd $E && COURSE=9 GROUP=3 AN=30 node class_test.js)
(cd $E && CMID=24 node newclass_test.js)
(cd $E && COURSE=9 node students_test.js)
$PHP $E/fixture.php teardown
```

`setup` tạo khoá `qptest`, các tài khoản `qp_student` / `qp_teacher` / `qp_hv1-5`
(mật khẩu cố định, xem `fixture.php`) và nhập lại đề từ `--dir` (mặc định
`D:/Learn/lam_viec/moodle/De_1`). `score_check.php` không có `--keep` thì tự xoá
lượt làm của nó. `class_check.php` chỉ chạy được **một lần** mỗi fixture (từ chối
nếu `qp_hv*` đã có lượt làm), và đổi khoá thử sang chế độ nhóm "thấy nhau được".

**Ảnh chụp trang có ngăn mục lục khoá học (Boost):** đừng dùng `fullPage`. Chụp cả
trang vẽ ngăn bên trái (`position: fixed`) đè lên nội dung, dù trên màn hình thật
không hề đè — đã đo bằng `getBoundingClientRect()` ở chiều cao 900 và 1600 px.

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
