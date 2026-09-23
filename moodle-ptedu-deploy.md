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

### ✅ Số đo thật — 2026-09-23

Đo trên lượt làm `#142`, một học viên, trọn phần nghe 10:26 → 11:03.

**Nguồn: `C:\wamp64\logs\access.log` của Apache**, không phải DevTools. Log ghi mọi
request kèm số byte thật, và nó **đã ghi rồi** — lấy được cả sau khi trang đã chuyển,
không cần làm lại bài. (DevTools mất log khi chuyển trang nếu quên tick **Keep log**.)

|                                              | Mỗi học viên                 | × 200 học viên  |
| -------------------------------------------- | ------------------------------- | ------------------ |
| File nghe                                    | **52,07 MB** (20 request) | **10,2 GB**  |
| Cả phiên thi (HTML + ảnh + CSS/JS + nghe) | **58,36 MB**              | **11,4 GB**  |
| Tốc độ trung bình lúc đang nghe        | 23,3 KB/s                       | **~37 Mbps** |

**Chrome stream thật, không tải nguyên file một lúc.** Nó lấy từng khối ~4 MB, đều
đặn mỗi **~3 phút 17 giây**, giữ buffer đi trước tiếng đọc. Nên tốc độ nền rất nhẹ —
37 Mbps cho 200 người là chuyện nhỏ với bất kỳ VPS nào.

### Nút thắt nằm ở 30 giây đầu, không phải lúc đang thi

| Thứ tải lúc mở trang làm bài | Dung lượng                     |
| ---------------------------------- | -------------------------------- |
| Buffer mở đầu của file nghe    | **10,0 MB**                |
| 6 ảnh Part 1                      | 3,5 MB                           |
| HTML trang làm bài (123 câu)    | 0,5 MB                           |
| **Cộng**                    | **~14 MB mỗi học viên** |

200 người cùng bấm "Bắt đầu" = **2,7 GB trong vài chục giây**:

| Học viên vào trong | Băng thông cần   |
| --------------------- | ------------------- |
| 30 giây              | **~750 Mbps** |
| 3 phút (chia ca)     | **~125 Mbps** |

> **Con số này biến "chia ca vào phòng lệch 2–3 phút" từ lời khuyên chung thành
> yêu cầu bắt buộc.** Nó cắt đỉnh băng thông đi 6 lần, không tốn một đồng nào.

### Đính chính ghi chú cũ

Bản đầu của file này (2026-09-23, trước khi đo) viết: *"lifetime 0 → trình duyệt
không cache → mỗi lần F5 là một lần tải lại"*. **Sai.**

Log cho thấy **9 lần trả `304 Not Modified`** — trình duyệt **có** cache, mỗi khối
chỉ hỏi lại xem file còn mới không rồi dùng bản đã có, tốn 0 byte. `lifetime 0` chỉ
thêm một vòng hỏi-đáp cho mỗi khối, không thêm byte nào. **Giữ nguyên** — nó tồn tại
để `can_listen()` được kiểm lại ngay khi lượt làm nộp xong.

### Đo lại bất cứ lúc nào

```bash
grep "local_quizportal/listening" /c/wamp64/logs/access.log \
  | awk '$10 ~ /^[0-9]+$/ {s+=$10; n++} END {printf "%d request, %.2f MB\n", n, s/1048576}'
```

Trên Linux sau này: `/var/log/nginx/access.log`, nhớ để format log có cột byte
(`$body_bytes_sent` — mặc định của `combined` đã có).

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

|              | Pilot (20–30 người) | Thi thật 200 người                                                                                                      |
| ------------ | ---------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| vCPU         | 4                      | **8**                                                                                                                |
| RAM          | 8 GB                   | **16 GB**                                                                                                            |
| Đĩa        | 100 GB NVMe            | **200 GB NVMe**                                                                                                      |
| Băng thông | 200 Mbps               | **1 Gbps** nếu thả 200 người vào cùng lúc; **300 Mbps** là đủ nếu chia ca lệch 2–3 phút (mục 1) |

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

|            |                                                                                          |
| ---------- | ---------------------------------------------------------------------------------------- |
| PHP        | **8.1 – 8.3**, chỉ 64-bit. Ext `sodium` bắt buộc. `max_input_vars ≥ 5000` |
| CSDL       | MariaDB ≥**10.6.7** · MySQL ≥ **8.0** · PostgreSQL ≥ **13**       |
| Web server | **nginx + PHP-FPM** (khuyến nghị)                                                |

PHP 8.1.33 đang dùng chuyển sang được luôn. Nguồn:
[https://moodledev.io/general/releases/4.5](https://moodledev.io/general/releases/4.5)

---

## 3. Giai đoạn 0 — GẤP, làm trước cả khi mua server

- [X] **Push code lên private repo** (GitHub/GitLab).
  Code **chỉ nằm trên máy này** — `origin` là `git.moodle.org`, chỉ đọc. Ổ cứng hỏng
  là mất toàn bộ 11 phiên làm việc. **Đây là rủi ro lớn nhất trong cả file này.**
- [X] **Commit 4 mảng đang dang dở** — B3 (bảng lớp), sao lưu/khôi phục, B2 (tạo lớp),
  B4 (quản lý học viên). `git status` đang có ~25 file untracked.
- [X] **Script đặt lại 4 thứ không có trong git** — `local/quizportal/cli/site_settings.php`
  (viết 2026-09-23). Cài lại site mà quên là cổng học viên không gắn vào site, và tên
  hiển thị bị ngược:

  | Thứ                          | Ở đâu          | Giá trị              |
  | ----------------------------- | ----------------- | ---------------------- |
  | `$CFG->alternateloginurl`   | `config.php:24` | URL cổng học viên   |
  | `fullnamedisplay`           | CSDL              | `lastname firstname` |
  | `alternativefullnameformat` | CSDL              | `lastname firstname` |
  | `authloginviaemail`         | CSDL              | `1`                  |

  Cách dùng:


  ```
  php -d max_input_vars=5000 local/quizportal/cli/site_settings.php            kiểm tra
  php -d max_input_vars=5000 local/quizportal/cli/site_settings.php --apply    đặt lại
  ```

  Thoát 0 khi mọi thứ đúng, 1 khi còn việc — dùng được trong script cài đặt.

  **`alternateloginurl` script chỉ báo cáo, không tự sửa.** Nó nằm trong `config.php`
  nên là *forced setting*: `set_config()` sẽ ghi một dòng vào CSDL mà site đang chạy
  bỏ qua — tệ hơn là không làm gì. Và `config.php` là file mà một lần sửa hỏng là sập
  cả site. Script in ra đúng dòng cần dán.

  Script kiểm tra kèm `allowaccountssameemail = 0` — điều kiện để `authloginviaemail`
  không nhập nhằng. Nó cũng **không** tự tắt cái này: nếu site đã có hai tài khoản
  trùng email thì tắt đi sẽ chặn họ đăng nhập, phải xem dữ liệu trước.

---

## 4. Giai đoạn 1 — Đo và thử, trước khi chốt cấu hình

- [X] **Đo băng thông thật của file nghe** — xong 2026-09-23, lượt `#142`.
  **52,07 MB / lượt nghe · 58,36 MB / cả phiên thi → 11,4 GB cho 200 người.**
  Kết luận và cách đo lại: mục 1. Điều bất ngờ: tốc độ nền rất nhẹ (~37 Mbps),
  đỉnh nằm ở 30 giây đầu (~750 Mbps nếu không chia ca).
- [ ] **Thử Safari + Firefox + iPhone thật.**
  `CLAUDE.md` ghi rõ là chưa từng thử. Chính sách tự phát âm thanh của Safari khác
  Chrome — nếu bị chặn thì **phần nghe hỏng hoàn toàn** với học viên dùng iPhone/Mac.
  Đây là rủi ro kỹ thuật lớn thứ hai sau băng thông.
- [X] **Một người nghe trọn 46 phút có tai nghe.**
  Cũng chưa ai làm. Lượt thử thật duy nhất (2026-09-22, `hv01`) bị tua, cả bài chỉ
  9 phút.
- [ ] **Chuyển mp3 sang 64 kbps mono và nghe thử.**

---

## 5. ⚠️ Rủi ro riêng của dự án này — đồng hồ nghe chạy theo server

Vị trí băng = `bây giờ − listenstart`, lưu ở bảng `local_quizportal_attemptstate`.

**Server sập 10 phút giữa phần nghe = mọi học viên mất vĩnh viễn 10 phút băng**,
không lấy lại được, và bài thi coi như hỏng.

- [X] **Vá lỗ hổng này** — `local/quizportal/cli/exam_recovery.php` (viết 2026-09-23,
  test 37/37). Trả lại thời gian cho **cả phòng** trong một lệnh.
- [X] **Viết quy trình khẩn cấp** — bên dưới. In ra dán trong phòng máy.

> Đây là khác biệt giữa "sự cố nhỏ, lùi băng 10 phút, thi tiếp" và "phải tổ chức
> thi lại cho cả 200 người".

### Sự cố gây ra **ba** thiệt hại, không phải một

Điều này chỉ lộ ra khi đọc kỹ code — ghi lại để khỏi sửa nửa vời:

| Thiệt hại | Cơ chế |
| --- | --- |
| Băng chạy tiếp lúc server chết | vị trí = `bây giờ − listenstart` |
| **Đồng hồ quiz cũng mất từng ấy phút** | `end_time = timestart + timelimit` (`quizaccess_timelimit`) |
| **Ai bấm F5 sau khi server sống lại bị đẩy sang trang chuyển tiếp VĨNH VIỄN** | `attempt_state::section()` tự đóng phần nghe khi `bây giờ > listenstart + độ dài + 90s` |

Cái thứ ba là độc nhất: nó **trừng phạt đúng những người mất nhiều nhất** (ai đang ở
gần cuối băng), và `set_listening_position()` từ chối làm việc sau khi `listenend` đã
được ghi. Nên công cụ cứu hộ phải **kéo lại được** phần nghe đã bị đóng.

### Công cụ

```
exam_recovery.php --list                           đề nào đang có người thi
exam_recovery.php --quiz=17                         ai đang ở đâu (chỉ đọc)
exam_recovery.php --quiz=17 --give-back=12          xem trước, KHÔNG ghi
exam_recovery.php --quiz=17 --give-back=12 --apply  thật sự trả lại 12 phút
```

Cờ thêm: `--reopen` kéo lại những lượt bị tự đẩy sang trang chuyển tiếp ·
`--no-quiztime` chỉ lùi băng, không cộng đồng hồ quiz.

**Nó dời mọi đồng hồ đi CÙNG một lượng**, không đặt tất cả về một mốc. Ai đang ở
40:00 quay về 28:00, ai ở 05:00 quay về 00:00 — mỗi người nghe tiếp đúng chỗ mất
điện, không ai nghe lại đoạn mà người bên cạnh chỉ được nghe một lần.

**Không có nút "tạm dừng", và không cần.** Ghi lại giờ sự cố rồi trả lại đúng ngần
ấy phút sau khi hồi phục là **cùng một phép toán** — mà không phải thêm cột CSDL nào.

### ⚠️ Quy trình khẩn cấp — IN RA DÁN TRONG PHÒNG MÁY

**Lúc phát hiện sự cố**

1. **Ghi giờ ngay.** `__:__:__` ← quan trọng nhất. Không có nó thì không biết trả lại bao nhiêu.
2. Nói học viên **đừng tắt trình duyệt, đừng bấm gì cả**, ngồi yên chờ.
3. Ghi giờ server sống lại: `__:__:__`

**Sau khi server sống lại — LÀM TRƯỚC KHI CHO HỌC VIÊN BẤM F5**

4. Tính số phút mất: `______ phút`
5. Xem ai đang ở đâu:
   `php local/quizportal/cli/exam_recovery.php --quiz=<ID>`
6. Xem trước (chưa ghi gì):
   `php ... exam_recovery.php --quiz=<ID> --give-back=<PHÚT>`
7. Đọc kỹ bảng. Có ai ở **"Trang chuyển tiếp"** không?
   - Có, và họ bị đẩy sang đó **vì sự cố** → thêm `--reopen`
   - Có, nhưng họ **nghe hết băng thật** rồi → **đừng** dùng `--reopen`
   - Nếu báo "quá hết băng" mà bạn chắc là do sự cố → sự cố dài hơn bạn tưởng, tính lại bước 4
8. Ghi thật: thêm `--apply`
9. **Bây giờ** mới bảo học viên bấm F5.
10. Dán dòng tổng kết script in ra vào biên bản sự cố.

> **Càng chờ lâu càng khó cứu.** Mỗi phút trôi qua là thêm học viên bị `section()`
> đẩy sang trang chuyển tiếp, và ai đã bấm "Bắt đầu phần đọc" thì `--reopen` cũng
> không kéo lại được — họ đã thấy đề đọc rồi.

### Còn mở

- [ ] Công cụ chỉ có ở CLI (cần SSH). Trong phòng thi người trực thường là giáo viên,
  không phải quản trị hệ thống. Cân nhắc làm trang web cho admin — nhưng việc này nằm
  trong nhóm "mọi thứ về giáo viên, để sau".
- [ ] Chưa ghi vào log sự kiện của Moodle. Hiện chỉ in ra một dòng để dán vào biên bản.

---

## 6. Giai đoạn 2 — Hạ tầng

- [ ] VPS Linux (Ubuntu 24.04) + nginx + PHP-FPM 8.3 + MariaDB 10.11
- [ ] Tên miền + **HTTPS** (Let's Encrypt), `$CFG->wwwroot` dùng `https://`
  — không có HTTPS thì mật khẩu bay trần trên mạng
- [ ] `moodledata` đặt **ngoài** web root,i quyền đúng
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

- Yêu cầu Moodle 4.5: [https://moodledev.io/general/releases/4.5](https://moodledev.io/general/releases/4.5)
- Giá VPS Việt Nam 2026: [https://azdigi.com/blog/kien-thuc-vps/bang-gia-thue-vps-viet-nam](https://azdigi.com/blog/kien-thuc-vps/bang-gia-thue-vps-viet-nam)
  · [https://fptcloud.com/bang-gia-thue-vps/](https://fptcloud.com/bang-gia-thue-vps/) · [https://cloud.vnpt.vn/blog/bang-gia-thue-vps-308](https://cloud.vnpt.vn/blog/bang-gia-thue-vps-308)
