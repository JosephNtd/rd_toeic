# Hướng dẫn số hoá đề TOEIC từ PDF

Tài liệu cho người nhập liệu. Mục tiêu: biến một đề TOEIC dạng PDF + file nghe
thành một file `.zip` mà hệ thống nhập được.

Hai file mẫu nằm cùng thư mục này:

| File | Dùng khi nào |
|---|---|
| `toeic-mau-trong.xlsx` | Bắt đầu một đề mới — điền vào đây |
| `toeic-mau-co-du-lieu.xlsx` | Đọc trước để hiểu cách điền. Có 14 câu ví dụ đủ cả 7 Part |

Sinh lại file mẫu bất cứ lúc nào:

```
php local/quizportal/cli/make_template.php --dir=C:/duong/dan/nao/do
```

---

## Bước 0 — Kiểm tra PDF thuộc loại nào

Mở PDF, thử **bôi đen một dòng chữ**:

- **Bôi đen được** → PDF dạng text. Copy-paste thẳng sang Excel, nhanh.
- **Không bôi đen được** → PDF là ảnh scan. Phải OCR trước, nếu không phải gõ tay 100%.

Với bản scan, công cụ OCR miễn phí dùng được: Google Drive (tải PDF lên, mở bằng
Google Docs — nó tự OCR), hoặc Adobe Acrobat nếu có bản quyền. **OCR luôn sai vài
chỗ**, nhất là số thứ tự câu và các chữ in nghiêng, nên vẫn phải dò lại.

> Đề TOEIC tải trên mạng gần như luôn có bản quyền của ETS. Rủi ro pháp lý là
> của trung tâm, không phải của hệ thống. Cân nhắc dùng đề tự soạn hoặc đề có
> giấy phép.

---

## Bước 1 — Dựng bộ khung

Tạo một thư mục cho mỗi đề:

```
De-01/
├── de-01.xlsx          ← copy từ toeic-mau-trong.xlsx
└── media/
    ├── listening.mp3   ← MỘT file cho cả Part 1–4
    ├── p1_01.jpg       ← ảnh Part 1
    ├── p1_02.jpg
    └── ...
```

Xong thì nén cả thư mục `De-01` thành `De-01.zip`.

**Quy tắc media:** mọi file ảnh và âm thanh phải nằm trong `media/`. Trong Excel
chỉ ghi **tên file**, không ghi đường dẫn — viết `p1_01.jpg`, không viết
`media/p1_01.jpg`.

---

## Bước 2 — Thứ tự nhập liệu (quan trọng)

Đừng nhập từ câu 1 đến câu 200 theo thứ tự. Làm theo thứ tự này nhanh hơn nhiều
vì mỗi lượt chỉ làm một loại việc:

**1. Đáp án trước tiên — cột `answer`, cả 200 câu.**
Đáp án thường nằm gọn một bảng ở cuối sách. Điền một mạch 200 ô, 15 phút là xong.
Làm trước còn vì nếu bảng đáp án lệch số thứ tự, phát hiện ngay từ đầu thay vì
sau khi đã gõ xong hết.

**2. Part 5, 6, 7 — phần đọc.**
Toàn bộ nội dung nằm trong PDF, copy-paste được, không cần nghe. Đây là ~100 câu,
tức một nửa đề, mà lại là phần dễ nhất. Part 6 và 7 nhớ tạo mã ngữ liệu bên sheet
`passages` trước, rồi mới trỏ tới từ sheet `questions`.

**3. Part 3, 4 — hội thoại và bài nói.**
Transcript thường in ở cuối sách. Mỗi hội thoại là **một dòng** bên sheet
`passages`, rồi 3 câu hỏi cùng trỏ vào một mã.

**4. Part 1, 2 — nghe thuần.**
Cột `text` và `a`–`d` **để trống** vì đề thi thật không in gì ra ngoài ảnh và ô
đáp án. Chỉ cần `answer`, và nên điền `transcript` để trang xem kết quả có cái
để hiện.

**5. Mốc thời gian — làm sau cùng, một lượt.**
Xem bước 3.

---

## Bước 3 — Lấy mốc thời gian cho file nghe

Cả Part 1–4 dùng **một file mp3 duy nhất**, nên mỗi câu cần biết nó bắt đầu ở
giây thứ mấy. Chỉ cần mốc **bắt đầu** — mốc kết thúc hệ thống tự suy ra từ mốc
của nhóm câu kế tiếp.

> **Cách tự động (khuyên dùng):** `local/quizportal/tools/README.md`. Máy tự
> nghe file mp3, điền đủ 100 mốc, và soát luôn lời thoại đã nhập (chữ dính, số
> câu lẫn vào, chỗ chép sai). Đề 1 mất ~6 phút máy chạy thay vì ~1 giờ nghe tay.
> Cách làm tay dưới đây giữ lại cho máy không cài được Python.

Cách làm tay, dùng **Audacity** (miễn phí):

1. Mở `listening.mp3`.
2. Nghe, tới chỗ bắt đầu mỗi câu thì bấm **Ctrl+B** để đánh dấu.
3. Xong hết thì `File → Export → Export Labels` ra file text có sẵn mốc giây.
4. Đổi giây sang `mm:ss` rồi dán vào cột `audio_start`.

Không có Audacity thì dùng VLC, xem đồng hồ và ghi tay cũng được, chỉ chậm hơn.

**Part 3 và 4:** ba câu cùng một hội thoại thì điền **cùng một mốc** — mốc lúc
hội thoại bắt đầu, không phải lúc đọc từng câu hỏi. Khi học viên nghe lại ở
trang kết quả, hệ thống phát lại cả đoạn hội thoại.

**Part 5, 6, 7:** để trống, không có âm thanh.

---

## Bước 4 — Kiểm tra trước khi nhập

Chạy lệnh này để máy soát lỗi giúp, trước khi đụng tới hệ thống thật:

```
php local/quizportal/cli/validate_workbook.php --file=De-01/de-01.xlsx --mediadir=De-01/media
```

Nó liệt kê **mọi lỗi cùng lúc**, kèm đúng sheet và số dòng, nên sửa được cả loạt
trong một lần thay vì sửa một lỗi lại chạy lại.

Ví dụ kết quả:

```
LỖI (3)
  - Sheet questions, dòng 4: đáp án "D" không hợp lệ cho Part 2, chỉ nhận A, B, C.
  - Sheet questions, dòng 10: thiếu phương án C.
  - Sheet meta: không tìm thấy file âm thanh "listening.mp3" trong thư mục media/.
```

**Lỗi** thì phải sửa. **Cảnh báo** thì đọc rồi tự quyết — ví dụ "Part 1 có 2 câu,
đề đầy đủ là 6 câu" là bình thường nếu bạn đang làm đề rút gọn.

---

## Những chỗ hay sai

| Hiện tượng | Nguyên nhân | Cách tránh |
|---|---|---|
| Mốc thời gian biến thành `12:00:00 AM` | Excel tự đổi `0:35` thành giá trị giờ | File mẫu đã khoá cột `audio_start` thành dạng Text. Nếu vẫn bị, gõ dấu nháy đơn trước: `'0:35` |
| Số thứ tự "nhảy cóc" | Xoá nhầm một dòng | Trình kiểm tra bắt được. Không tự đánh lại số — tìm đúng dòng bị mất |
| Ảnh "không tìm thấy" dù có file | Sai hoa/thường hoặc thừa đuôi | Tên trong Excel phải khớp tên file. `P1_01.JPG` và `p1_01.jpg` được coi là một |
| Part 2 có 4 phương án | Nhầm với các Part khác | Part 2 chỉ có A, B, C |
| Đoạn văn Part 7 lặp ở nhiều dòng | Điền vào sheet `questions` thay vì `passages` | Đoạn văn chỉ viết **một lần** ở `passages`, câu hỏi trỏ tới bằng mã |
| Xuống dòng trong ô không được | Enter làm nhảy ô | Dùng **Alt+Enter** để xuống dòng trong cùng một ô |

---

## Chia việc cho nhiều người

Các bước ở trên độc lập nhau nên chia được:

- **Người A** — Part 5, 6, 7 (phần đọc, không cần tai nghe)
- **Người B** — Part 1, 2, 3, 4 + transcript (cần tai nghe)
- **Người C** — mốc thời gian, làm sau khi B xong

Mỗi người giữ một file riêng rồi ghép lại, hoặc dùng Google Sheets rồi tải về
dạng `.xlsx` ở bước cuối. Nếu dùng Google Sheets, nhớ **định dạng cột
`audio_start` thành Plain text** trước khi gõ.

---

## Ước lượng thời gian

Một đề 200 câu, người quen việc:

| Việc | Thời gian |
|---|---|
| Đáp án | ~15 phút |
| Part 5, 6, 7 (~100 câu) | 2–3 giờ nếu copy-paste được; 5–6 giờ nếu gõ tay |
| Part 1–4 + transcript | 2–3 giờ |
| Mốc thời gian | ~1 giờ |

Đề đầu tiên luôn lâu nhất. Từ đề thứ hai trở đi nhanh hơn rõ rệt.
**Làm thử một đề rút gọn 20 câu trước** để chạy hết quy trình rồi hãy làm đề đầy đủ.
