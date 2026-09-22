# Công cụ lấy mốc `audio_start` tự động

Thay cho việc ngồi nghe Audacity và bấm Ctrl+B cả trăm lần (bước 3 trong
`samples/HUONG-DAN-NHAP-DE.md`). Hai công cụ, dùng nối tiếp nhau:

| | Việc | Chạy bằng |
|---|---|---|
| `tools/audio_marks.py` | Nghe file mp3, tìm chỗ bắt đầu của từng câu, tự kiểm tra từng mốc | Python |
| `cli/apply_audio_marks.php` | Ghi mốc vào file đề `.xlsx`, **và** so lời thoại trong file đề với audio | PHP của WAMP |

Đã chạy thật trên đề 1 (`De_1`): ra đủ 100/100 mốc, khớp từng giây với bộ mốc
đã kiểm tra bằng tay.

## Cài một lần

```bash
pip install faster-whisper
```

Lần chạy đầu tự tải model `small.en` (~460 MB) về `~/.cache/huggingface`.
Không cần mạng từ lần thứ hai.

*GPU (không bắt buộc):* máy có card NVIDIA vẫn chạy CPU nếu thiếu thư viện
CUDA — công cụ tự báo và tự chuyển. Muốn chạy GPU thì
`pip install nvidia-cublas-cu12 nvidia-cudnn-cu12`. CPU mất khoảng 5–6 phút cho
một file nghe 46 phút (đề 1: 327 giây).

## Cách dùng

Thư mục đề có dạng quen thuộc:

```
De_2/
  DE_02.xlsx
  media/Test_02.mp3
```

**1. Dò mốc**

```bash
python local/quizportal/tools/audio_marks.py --audio D:/.../De_2/media/Test_02.mp3
```

Ra file `De_2/Test_02.marks.json` (và `Test_02.marks.words.json` — bản nhận
dạng giọng nói, giữ lại để lần sau chạy lại khỏi chờ). Cuối cùng in một trong hai:

- `Mọi mốc đều qua kiểm tra.` → sang bước 2.
- `CẦN XEM LẠI: …` → mở file nghe ở đúng giây được nêu mà nghe. Bước 2 sẽ không
  chịu ghi cho tới khi thêm `--force`.

**2. Xem trước rồi ghi vào file đề**

Đóng Excel trước (file đang mở thì công cụ từ chối ghi).

```bash
C:/wamp64/bin/php/php8.1.33/php.exe -d max_input_vars=5000 local/quizportal/cli/apply_audio_marks.php --file=D:/.../De_2/DE_02.xlsx --marks=D:/.../De_2/Test_02.marks.json
```

Chạy không có `--write` thì **chỉ in ra**, không ghi gì. Đọc xong thì chạy lại, thêm:

| Cờ | Tác dụng |
|---|---|
| `--write` | Ghi thật. Bản cũ được chép vào `De_2/backup/` trước |
| `--titles` | Sửa luôn tiêu đề nhóm Part 3/4 theo đúng lời đọc ("…following **recorded message**", "…**with three speakers**") |
| `--overwrite` | Thay cả mốc đã điền tay. Mặc định chỉ điền ô trống, mốc nào khác audio thì liệt kê ra |

Sau đó chạy `validate_workbook.php` như mọi khi rồi nhập đề.

## Đọc phần "Lời thoại … cần xem"

Bước 2 so **từng lời thoại trong file đề** với những gì audio thật sự nói, và
soi luôn các lỗi hay gặp khi chép từ PDF. Chạy trên bản đề 1 *trước khi sửa*,
nó chỉ ra đúng 36 chỗ hỏng mà trước đó phải đọc 30 trang PDF mới tìm thấy:

| Báo | Thường là |
|---|---|
| `chữ dính liền: Doyouwanttoeathere…` | Copy từ PDF mất dấu cách |
| `số câu của sách lẫn vào lời thoại: 50` | Sách in số câu nhỏ phía trên chữ (`⁵⁰I'm`) để đánh dấu gợi ý đáp án — copy ra thành `50I'm` |
| `lời thoại đang trống` | Chưa nhập |
| `chỉ 30% số từ nghe thấy` | Nhập sai / dính chữ nặng |
| `chỉ 85–89%` + danh sách vài từ | Thường chỉ là khác cách viết: tên riêng (`Cranbury`/`Cranberry`), `8 a.m.`/`8am`, `centre`/`center`. Nhìn qua là biết |

**Không bắt được:** nhãn người nói bị đảo (`W-Am` ↔ `M-Au`) — nghe nội dung
không phân biệt được ai nói. Vẫn phải đọc lướt.

## Mốc được lấy ở đâu

- **Part 1, 2 (câu 1–31):** lúc đọc "Number N", không phải lúc bắt đầu file.
- **Part 3, 4 (câu 32–100):** lúc đọc "Questions X through Y refer to…", **cùng
  một mốc cho cả 3 câu** của nhóm.
- Làm tròn **xuống** giây: thà sớm nửa giây còn hơn cắt mất chữ đầu.

## Vì sao làm phức tạp vậy — cho người sửa công cụ

Mốc lấy thẳng từ Whisper **sai**, đo trên đề 1:

1. Trong đoạn nói liền dài, giờ của từng từ **trôi vài giây**: câu 27 Whisper
   ghi "number" ở 704,7 s, tiếng thật ở 709,5 s — mốc rơi giữa lời câu 26.
2. Whisper **bỏ sót nguyên câu** "Questions 68 through 70…", kể cả khi chạy lại
   đoạn đó kèm ngữ cảnh.
3. Tách "twenty-one" thành hai từ.

Nên Whisper chỉ để biết *câu nào ở vùng nào*. Mốc thật = **điểm cuối của khoảng
lặng ngay trước tiếng đọc** (đo độ lớn âm thanh từng 50 ms). Trên đề 1 các khoảng
lặng này rất rõ: 5 s trước mỗi câu Part 1/2, 5–8 s trước mỗi nhóm Part 3/4,
~1,1 s sau phần hướng dẫn.

Câu nào Whisper bỏ sót thì công cụ **cắt riêng 7 giây** sau từng khoảng lặng dài
mà nghe lại. Đoạn cắt phải **bắt đầu 1 giây bên trong khoảng lặng** — cắt sát
tiếng thì Whisper nuốt mất "Number 21"; cho sẵn gợi ý "Number 21" thì còn tệ hơn.
Part 1/2 còn một đường lùi không cần nhận dạng: giữa câu N−1 và N+1 chỉ có đúng
một khoảng trả lời 5 giây.

Neo vào **con số** ("21", "68"), không vào chữ "Number"/"Questions": trong đoạn
cắt bắt đầu bằng khoảng lặng, Whisper kéo từ đầu tiên về sát đầu đoạn (sớm ~1 s),
làm mốc nhóm 68 nhảy lùi sang khoảng lặng của câu trước (30:17 thay vì 30:30).

Mỗi mốc phải qua 5 phép kiểm tra, trượt một là dừng: rơi vào im lặng; có tiếng
đọc trong 1,5 s sau đó; khoảng lặng trước ≥ 3 s (trừ câu đầu mỗi Part); tiếng đọc
không bắt đầu sớm hơn con số quá 2,5 s (bắt được lỗi 30:17 ở trên); các mốc tăng dần.

Giả định cấu trúc đề chuẩn: 1–6 Part 1, 7–31 Part 2, nhóm 3 câu từ 32 tới 100.
Đề rút gọn hay đánh số khác thì phải sửa `PART12_ITEMS` / `GROUP_FIRSTS`.
