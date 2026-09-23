# PTEducation — Danh sách việc để đưa dự án lên mạng

Mục tiêu của file này: đi từ site chạy trên WAMP ở máy cá nhân → site công khai
trên internet, chịu được **200 học viên thi cùng lúc**.

Lập ngày 2026-09-23. Trạng thái tổng của dự án: xem `CLAUDE.md`.

Tích `[x]` khi xong. Việc nào phát sinh thì thêm vào, và ghi lý do — file này
cũng là nhật ký triển khai.

---

## 1. Nút thắt thật sự — đọc trước khi mua server

Đừng ước lượng theo kiểu "200 người dùng Moodle". Đề TOEIC có **file nghe 42 MB**
và đó mới là thứ quyết định cấu hình.

Số đo thật: `Test_01.mp3` = **44.109.018 byte, 128 kbps, 45:53**.

File nghe **đi qua PHP**, không phải web server phục vụ thẳng — vì
`local_quizportal_pluginfile()` (`lib.php:75`) phải chạy `can_listen()` trên từng
request để kiểm tra người này có lượt làm đang chạy không.

### Hai kịch bản chênh nhau 20 lần

| Cách trình duyệt lấy file | Băng thông cho 200 người |
|---|---|
| Stream đúng nhịp phát (Range request) | ~16 KB/s mỗi người → **~26 Mbps** |
| Tải nguyên file, tất cả vào trong 5 phút | 8,8 GB → **~235 Mbps** |
| Tải nguyên file, tất cả vào trong 2 phút | **~590 Mbps** |

**Yếu tố khuếch đại:** `send_stored_file($file, 0, 0, ...)` đặt lifetime 0 → trình
duyệt **không cache**. Mà thiết kế phần nghe lại khuyến khích tải lại trang ("F5 là
nghe tiếp đúng chỗ băng đang chạy") → mỗi lần F5 là một lần tải lại.

> **Chưa ai đo con số thật.** Đây là việc số 1 ở Giai đoạn 1, làm được ngay, không
> tốn đồng nào: DevTools › Network › làm trọn một lượt → xem dòng mp3 báo
> *Transferred* bao nhiêu byte. Nhân 200. Con số đó chọn gói băng thông.

### Hai cách giảm tải, cả hai đều rẻ

**a) Bật `$CFG->xsendfile`** — gần như bắt buộc.

Đã xác minh chạy được với code hiện tại: `send_stored_file()` → `supports_xsendfile()`
(`lib/filestorage/file_system.php:499`) → nginx/Apache tự phục vụ file từ `filedir`.
PHP vẫn kiểm quyền như cũ nhưng **không bị giữ worker** suốt thời gian truyền.

Không có nó: 200 người tải đồng thời có thể chiếm hết PHP-FPM worker → autosave của
cả lớp treo theo.

**b) Giảm bitrate 128 kbps stereo → 64 kbps mono.**

Đề TOEIC chỉ là giọng nói; 64 kbps mono nghe không khác. **Cắt đúng một nửa băng
thông và một nửa dung lượng đĩa**, không đổi một dòng code nào. Làm ở khâu chuẩn bị
đề, trước khi nhập.

---

## 2. Cấu hình server đề xuất

### Đặt máy ở Việt Nam, không phải Singapore

Cáp quang biển VN (AAG/APG) đứt vài lần mỗi năm. Với website bình thường thì chỉ là
chậm; với **bài thi có đồng hồ chạy theo server** thì là thảm hoạ (xem mục 5). Máy
trong nước = traffic không ra quốc tế.

### Cấu hình

| | Pilot (20–30 người) | Thi thật 200 người |
|---|---|---|
| vCPU | 4 | **8** |
| RAM | 8 GB | **16 GB** |
| Đĩa | 100 GB NVMe | **200 GB NVMe** |
| Băng thông | 200 Mbps | **1 Gbps** (điều chỉnh theo số đo ở mục 1) |

**Vì sao 8 vCPU:** đỉnh CPU **không** phải lúc đang thi, mà là lúc 200 người cùng
bấm "Bắt đầu" — `attempt.php` render 123 câu trong một trang. Nếu mỗi lần render tốn
~2 s CPU và 200 người vào trong 1 phút thì cần ~7 lõi.

> Cách giảm rẻ nhất, không tốn tiền server: **chia ca vào phòng thi lệch nhau 2–3 phút**.

**Đừng mua gói lớn ngay.** Khởi động bằng cấu hình pilot, đo thật, rồi resize —
VPS chỉ mất một lần reboot.

Nhà cung cấp trong nước: FPT Cloud, Viettel IDC, VNPT Cloud, AZDIGI, Vietnix.
Khoảng giá 2026: từ ~80.000đ/tháng cho cấu hình cơ bản đến vài triệu cho gói hiệu
năng cao; gói 8–12 vCPU / 16 GB+ NVMe khoảng **trên 7 triệu/năm**. Không nhà nào
công bố giá đúng cấu hình này công khai — phải hỏi trực tiếp.

### Môi trường (yêu cầu chính thức của Moodle 4.5)

| | |
|---|---|
| PHP | **8.1 – 8.3**, chỉ 64-bit. Ext `sodium` bắt buộc. `max_input_vars ≥ 5000` |
| CSDL | MariaDB ≥ **10.6.7** · MySQL ≥ **8.0** · PostgreSQL ≥ **13** |
| Web server | **nginx + PHP-FPM** (khuyến nghị) |

PHP 8.1.33 đang dùng chuyển sang được luôn. Nguồn:
<https://moodledev.io/general/releases/4.5>

---

## 3. Giai đoạn 0 — GẤP, làm trước cả khi mua server

- [ ] **Push code lên private repo** (GitHub/GitLab).
  Code **chỉ nằm trên máy này** — `origin` là `git.moodle.org`, chỉ đọc. Ổ cứng hỏng
  là mất toàn bộ 11 phiên làm việc. **Đây là rủi ro lớn nhất trong cả file này.**
- [ ] **Commit 4 mảng đang dang dở** — B3 (bảng lớp), sao lưu/khôi phục, B2 (tạo lớp),
  B4 (quản lý học viên). `git status` đang có ~25 file untracked.
- [ ] **Viết script đặt lại 4 thứ không có trong git.**
  Cài lại site mà quên là cổng học viên không gắn vào site, và tên hiển thị bị ngược:

  | Thứ | Ở đâu | Giá trị |
  |---|---|---|
  | `$CFG->alternateloginurl` | `config.php:24` | URL cổng học viên |
  | `fullnamedisplay` | CSDL | `lastname firstname` |
  | `alternativefullnameformat` | CSDL | `lastname firstname` |
  | `authloginviaemail` | CSDL | `1` |

  Lệnh: `php admin/cli/cfg.php --name=<tên> --set="<giá trị>"`

---

## 4. Giai đoạn 1 — Đo và thử, trước khi chốt cấu hình

- [ ] **Đo băng thông thật của file nghe** (DevTools › Network, một lượt trọn vẹn).
  Xem mục 1. Kết quả đo được: `________ MB / lượt` → × 200 = `________ GB`
- [ ] **Thử Safari + Firefox + iPhone thật.**
  `CLAUDE.md` ghi rõ là chưa từng thử. Chính sách tự phát âm thanh của Safari khác
  Chrome — nếu bị chặn thì **phần nghe hỏng hoàn toàn** với học viên dùng iPhone/Mac.
  Đây là rủi ro kỹ thuật lớn thứ hai sau băng thông.
- [ ] **Một người nghe trọn 46 phút có tai nghe.**
  Cũng chưa ai làm. Lượt thử thật duy nhất (2026-09-22, `hv01`) bị tua, cả bài chỉ
  9 phút.
- [ ] **Chuyển mp3 sang 64 kbps mono và nghe thử.**

---

## 5. ⚠️ Rủi ro riêng của dự án này — đồng hồ nghe chạy theo server

Vị trí băng = `bây giờ − listenstart`, lưu ở bảng `local_quizportal_attemptstate`.

**Server sập 10 phút giữa phần nghe = mọi học viên mất vĩnh viễn 10 phút băng**,
không lấy lại được, và bài thi coi như hỏng.

Công cụ hiện có: `cli/listening_clock.php --to=MM:SS` kéo băng lùi lại — nhưng chạy
**từng học viên một**. Với 200 người thì không kịp trong lúc đang có sự cố.

- [ ] **Vá lỗ hổng này trước khi public** (ước lượng: nửa buổi).
  Thêm lệnh "lùi băng cho cả lớp N phút" hoặc "tạm dừng toàn lớp" cho admin, dựa
  trên `attempt_state::set_listening_position()` đã có.
- [ ] **Viết quy trình khẩn cấp** và in ra để sẵn trong phòng thi: server sập thì ai
  làm gì, theo thứ tự nào.

> Đây là khác biệt giữa "sự cố nhỏ, lùi băng 10 phút, thi tiếp" và "phải tổ chức
> thi lại cho cả 200 người".

---

## 6. Giai đoạn 2 — Hạ tầng

- [ ] VPS Linux (Ubuntu 24.04) + nginx + PHP-FPM 8.3 + MariaDB 10.11
- [ ] Tên miền + **HTTPS** (Let's Encrypt), `$CFG->wwwroot` dùng `https://`
  — không có HTTPS thì mật khẩu bay trần trên mạng
- [ ] `moodledata` đặt **ngoài** web root, quyền đúng
- [ ] **Cron mỗi phút** (`admin/cli/cron.php` qua systemd timer)
  — thiếu là: không gửi email, không dọn thùng rác, không backup tự động
- [ ] **SMTP thật** — tạo tài khoản, đặt lại mật khẩu, thông báo đều cần email
- [ ] **Redis** cho session + MUC cache — file session với 200 người thi = I/O nặng
- [ ] **`$CFG->xsendfile`** + module tương ứng của web server (xem mục 1)
- [ ] OPcache bật, `opcache.validate_timestamps = 0`
- [ ] `max_input_vars = 5000`
- [ ] Upload limit **≥ 200M** — zip đề có mp3 42 MB + media; mức 64M hiện tại sẽ chật
- [ ] `innodb_buffer_pool_size` ≈ 50% RAM
- [ ] Chạy `admin/cli/upgrade.php` + `purge_caches.php` sau khi đưa code lên

---

## 7. Giai đoạn 3 — Bảo mật

- [ ] **⚠️ Xem lại quyết định "mật khẩu đầu = ngày sinh DDMMYYYY".**
  Trong phòng thi nội bộ thì ổn. Trên internet công cộng, ai biết **email + ngày
  sinh** là đăng nhập được — mà trong một lớp thì cả hai đều dễ biết.
  Hai hướng: bắt đổi mật khẩu lần đầu, hoặc sinh mật khẩu ngẫu nhiên in trên phiếu
  tài khoản (`account_sheet.mustache` đã có sẵn).
  *Quyết định của trung tâm: ______________*
- [ ] Mật khẩu admin mạnh; rà lại các tài khoản thử còn sót (`hv01`–`hv10`)
- [ ] `debug = 0`, `debugdisplay = 0`
- [ ] Firewall chỉ mở 80/443 + SSH (key-only, đổi port), fail2ban
- [ ] Chạy **Security overview** trong Moodle, xử hết cảnh báo đỏ
- [ ] **Backup tự động: dump CSDL + `moodledata`, đẩy ra nơi khác** (S3/Backblaze/Drive).
  Hiện chưa có backup gì cả.
- [ ] Thử **khôi phục** từ bản backup một lần — backup chưa thử khôi phục thì chưa
  phải backup

---

## 8. Giai đoạn 4 — Tải và diễn tập

- [ ] **Load test** (k6 hoặc JMeter), hoặc thực tế hơn: cho một lớp 30 người thi thử
- [ ] Chia ca vào phòng lệch 2–3 phút để dàn đỉnh CPU (xem mục 2)
- [ ] Diễn tập quy trình khẩn cấp ở mục 5
- [ ] Có người trực kỹ thuật trong giờ thi, có số điện thoại dán trong phòng

---

## 9. Giai đoạn 5 — Vận hành & pháp lý

- [ ] Monitoring uptime (UptimeRobot) + cảnh báo đầy đĩa
- [ ] Site **staging** riêng để thử trước khi đẩy lên production
- [ ] **Nghị định 13/2023** về bảo vệ dữ liệu cá nhân — site lưu họ tên, email,
  **ngày sinh** của học viên. Cần chính sách riêng tư tối thiểu.
- [ ] **Bản quyền đề ETS** — dùng trong lớp khác với đưa lên internet. Trung tâm tự
  cân nhắc, nhưng nên quyết định có ý thức chứ đừng để mặc định.
- [ ] **Kế hoạch nâng cấp Moodle.** 4.5 là LTS nhưng **đã hết hỗ trợ sửa lỗi chung**,
  chỉ còn vá bảo mật. May là nhánh `MOODLE_405_STABLE` còn sạch → nâng cấp bằng
  `git merge` được, đúng như quy ước ở `CLAUDE.md` mục 6.

---

## 10. Còn treo trong code (theo `CLAUDE.md`)

- [ ] **Giáo viên: chưa có gì.** Giáo viên đăng nhập qua cổng rơi vào dashboard học
  viên. Nếu giáo viên cần dùng khi public thì phải làm trước.
  *(Người dùng dặn 2026-09-22: mọi thứ về giáo viên để sau, sẽ báo khi nào bắt đầu.)*
- [ ] Theme chưa ai duyệt bằng mắt ở trang admin và trang khoá học
- [ ] Track C — dọn dẹp
- [ ] Duyệt các mặc định của B4 (lưu ngày sinh vào hồ sơ, chuyển lớp = đình chỉ ghi danh)
- [ ] Trình kiểm tra im lặng khi thiếu `audio_start` — nên thêm một cảnh báo gộp

---

## 11. Nếu chỉ làm được 3 việc tuần này

1. **Push code lên repo riêng** (mục 3)
2. **Đo băng thông file nghe** (mục 4)
3. **Thử Safari / iPhone** (mục 4)

Cả ba đều miễn phí, và cả ba đều có thể làm thay đổi kế hoạch mua server.

---

## Nguồn

- Yêu cầu Moodle 4.5: <https://moodledev.io/general/releases/4.5>
- Giá VPS Việt Nam 2026: <https://azdigi.com/blog/kien-thuc-vps/bang-gia-thue-vps-viet-nam>
  · <https://fptcloud.com/bang-gia-thue-vps/> · <https://cloud.vnpt.vn/blog/bang-gia-thue-vps-308>
