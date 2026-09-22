#!/usr/bin/env python3
"""Find where every Listening item starts in a TOEIC recording.

Produces the mm:ss marks for the workbook's audio_start column, and the title
of each Part 3/4 group exactly as the recording announces it, then checks
every mark against the audio. Apply the result with cli/apply_audio_marks.php.

    python local/quizportal/tools/audio_marks.py --audio D:/De_2/media/Test_02.mp3

Requires:  pip install faster-whisper
The first run downloads the small.en model (about 460 MB) to ~/.cache/huggingface.

Where a mark is taken (samples/HUONG-DAN-NHAP-DE.md, step 3):
  Part 1/2  item 1-31  : where the reader says "Number N"
  Part 3/4  item 32-100: where "Questions X through Y refer to ..." begins,
                         the same mark for all three questions of the group

Why both speech recognition and loudness (README.md has the measurements):
Whisper says which item is where, but inside long stretches of speech its
word times drift by seconds, and now and then it drops a sentence altogether.
So Whisper only locates each item; the mark itself is where speech resumes
after the pause that precedes it, read off the audio.
"""
import argparse
import json
import math
import os
import re
import sys
import time

try:
    import numpy as np
    from faster_whisper import WhisperModel
    from faster_whisper.audio import decode_audio
except ImportError:
    sys.exit("Thiếu thư viện. Cài bằng:  pip install faster-whisper")

SR = 16000
FRAME = 0.05              # loudness is measured in 50 ms frames
SPEECH_DB = -40.0         # frames louder than this are speech (the silences here sit near -80 dB)
MIN_PAUSE = 0.9           # a lead-in pause is at least this long. The breath between
                          # "Number 88." and its question (~0.5 s) must not qualify.
LATE_TOLERANCE = 0.8      # Whisper can place a word up to this much after speech starts
LEAD = 0.15               # marks are floored from onset - LEAD, so they never clip a word
MAX_ONSET_DELAY = 1.5     # check: speech must begin within this long after a mark
ANSWER_PAUSE = 3.0        # check: items after the first of a part follow an answer pause (5-8 s)
MAX_ANCHOR_LAG = 2.5      # check: speech onset to the spoken digit ("Questions 68", "Number 21")
CLIP_SECONDS = 7.0        # clip length when re-listening for a sentence Whisper skipped

PART12_ITEMS = range(1, 32)
GROUP_FIRSTS = range(32, 99, 3)
FIRST_OF_PART = {1, 7, 32, 71}  # follow the directions, so only a short pause precedes them

ONES = ("zero one two three four five six seven eight nine ten eleven twelve thirteen "
        "fourteen fifteen sixteen seventeen eighteen nineteen").split()
TENS = {"twenty": 20, "thirty": 30, "forty": 40, "fifty": 50,
        "sixty": 60, "seventy": 70, "eighty": 80, "ninety": 90}


# --- Words ---------------------------------------------------------------------

def clean(token):
    return re.sub(r"[^a-z0-9-]", "", token.lower())


def to_int(token):
    """'7', '7.', 'seven', 'Twenty-one,' -> int; anything else -> None."""
    t = clean(token)
    if t.isdigit():
        return int(t)
    if t in ONES:
        return ONES.index(t)
    if t in TENS:
        return TENS[t]
    m = re.fullmatch(r"([a-z]+)-([a-z]+)", t)
    if m and m.group(1) in TENS and m.group(2) in ONES[1:10]:
        return TENS[m.group(1)] + ONES.index(m.group(2))
    return None


def glue(words):
    """Whisper splits 'twenty-one' into 'twenty' + '-one.'; put them back together."""
    out = []
    for w in words:
        if out and w["w"].strip().startswith("-"):
            out[-1] = {"w": out[-1]["w"] + w["w"].strip(), "s": out[-1]["s"], "e": w["e"]}
        else:
            out.append(dict(w))
    return out


def item_at(words, i, n):
    """Is words[i] the announcement of Part 1/2 item n? Returns the anchor time or None.

    Anchors on the digit, not on "Number": inside run-on segments Whisper's
    "Number" can drift by seconds while the digit stays close.
    """
    if to_int(words[i]["w"]) != n:
        return None
    prev = clean(words[i - 1]["w"]) if i >= 1 else ""
    prev2 = clean(words[i - 2]["w"]) if i >= 2 else ""
    nxt = clean(words[i + 1]["w"]) if i + 1 < len(words) else ""
    if prev in ("number", "numbers"):
        # Not "...picture marked number N" (Part 1) nor "...begin with question
        # number seven" (Part 2 lead-in): the item is the bare "Number N." around them.
        return None if prev2 in ("marked", "question") else words[i]["s"]
    if prev in ("marked",):
        return None
    # Whisper sometimes drops the word "Number" and writes just "2."
    if n <= 6 and nxt == "look":
        return words[i]["s"]
    if n >= 7 and words[i]["w"].strip().endswith("."):
        return words[i]["s"]
    return None


def group_at(words, i, first):
    """Is words[i] the start of "Questions <first> through ..."? Returns (time, sentence) or None.

    The time is the digit's, as for Part 1/2. In a clip that starts in silence
    Whisper pulls the first word back to the clip start: "Questions" came out
    at 1829.7 s against speech at 1830.7 s, which made the next step settle on
    the pause before the previous question and mark 30:17 instead of 30:30.
    """
    if clean(words[i]["w"]) not in ("questions", "question"):
        return None
    if i + 1 >= len(words) or to_int(words[i + 1]["w"]) != first:
        return None
    spoken = []
    for w in words[i:i + 25]:
        spoken.append(w["w"])
        if w["w"].strip().endswith("."):
            break
    return words[i + 1]["s"], "".join(spoken).strip()


def title_from(sentence, first):
    """'Questions 62 through 64 refer to the following conversation and website.'
    -> 'Questions 62-64 refer to the following conversation and Web site.'"""
    m = re.search(r"following (.+?)\.?$", sentence)
    kind = m.group(1) if m else "?"
    kind = re.sub(r"\bwebsite\b", "Web site", kind, flags=re.I)
    return f"Questions {first}-{first + 2} refer to the following {kind}."


# --- Audio ---------------------------------------------------------------------

class Loudness:
    def __init__(self, audio):
        self.audio = audio
        per = int(SR * FRAME)
        n = len(audio) // per
        frames = audio[: n * per].reshape(n, per)
        self.db = 20 * np.log10(np.sqrt((frames ** 2).mean(axis=1)) + 1e-9)
        self.pauses = []  # (start, end) of silent runs of at least 0.3 s
        run = None
        for k, loud in enumerate(self.db > SPEECH_DB):
            if not loud and run is None:
                run = k
            elif loud and run is not None:
                if (k - run) * FRAME >= 0.3:
                    self.pauses.append((run * FRAME, k * FRAME))
                run = None

    def level(self, t0, t1):
        seg = self.audio[int(t0 * SR):int(t1 * SR)]
        return float(20 * np.log10(np.sqrt(np.mean(seg ** 2)) + 1e-9)) if len(seg) else -120.0

    def onset_before(self, anchor):
        """End of the last real pause at or shortly after the anchor: where the item's speech starts."""
        cands = [p for p in self.pauses if p[1] <= anchor + LATE_TOLERANCE and p[1] - p[0] >= MIN_PAUSE]
        return cands[-1] if cands else None

    def first_speech_after(self, t, within):
        for k in range(int(within / FRAME) + 1):
            x = t + k * FRAME
            if self.level(x, x + FRAME) > SPEECH_DB:
                return x
        return None


# --- Whisper -------------------------------------------------------------------

def load_model(name, device):
    order = [("cuda", "float16"), ("cpu", "int8")] if device == "auto" else \
        [(device, "float16" if device == "cuda" else "int8")]
    last = None
    for dev, ctype in order:
        for attempt in (1, 2):
            try:
                model = WhisperModel(name, device=dev, compute_type=ctype)
                # Loading can succeed on a GPU that then fails on first use
                # ("cublas64_12.dll is not found"), so make it do some work.
                list(model.transcribe(np.zeros(SR, dtype=np.float32), language="en")[0])
                print(f"Whisper {name} trên {dev}")
                return model
            except Exception as exc:  # noqa: BLE001 - try the next option
                last = exc
                # First download on Windows can fail creating symlinks (WinError
                # 1314) yet leave the files in place; a second try then works.
                if attempt == 1 and "1314" in str(exc):
                    continue
                if dev == "cuda":
                    print(f"Không dùng được GPU ({str(exc)[:90]}), chuyển sang CPU.")
                break
    sys.exit(f"Không nạp được model Whisper: {last}")


def transcribe(model, audio, offset=0.0):
    segments, _ = model.transcribe(audio, language="en", word_timestamps=True,
                                   condition_on_previous_text=False, beam_size=5)
    words = []
    for seg in segments:
        for w in seg.words or []:
            words.append({"w": w.word, "s": w.start + offset, "e": w.end + offset})
    return glue(words)


def cached_words(path, audio_path, model_name, get_model, audio):
    stat = os.stat(audio_path)
    key = {"audio": os.path.basename(audio_path), "size": stat.st_size, "model": model_name}
    if os.path.exists(path):
        with open(path, encoding="utf8") as fh:
            cache = json.load(fh)
        if all(cache.get(k) == v for k, v in key.items()):
            print(f"Dùng lại bản nhận dạng đã lưu: {path}")
            return glue(cache["words"])
    print("Nhận dạng giọng nói toàn bộ file (CPU mất khoảng 1/7 thời lượng audio)…")
    t0 = time.time()
    words = transcribe(get_model(), audio)
    print(f"  xong trong {time.time() - t0:.0f} giây, {len(words)} từ")
    with open(path, "w", encoding="utf8") as fh:
        json.dump(dict(key, words=words), fh, ensure_ascii=False)
    return words


# --- Finding the items -----------------------------------------------------------

def find_anchors(words):
    """Anchor time for items 1-31 and for the first question of each group."""
    part3 = next((w["s"] for i, w in enumerate(words[:-1])
                  if clean(w["w"]) == "part" and to_int(words[i + 1]["w"]) == 3), None)
    if part3 is None:
        part3 = next((g[0] for i in range(len(words)) if (g := group_at(words, i, 32))), float("inf"))

    anchors, sentences, cursor = {}, {}, 0.0
    for n in PART12_ITEMS:
        for i, w in enumerate(words):
            if cursor <= w["s"] < part3 and (t := item_at(words, i, n)) is not None:
                anchors[n] = t
                cursor = t + 3
                break
    for first in GROUP_FIRSTS:
        for i, w in enumerate(words):
            if w["s"] >= min(part3, cursor) and (g := group_at(words, i, first)):
                anchors[first], sentences[first] = g
                break
    return anchors, sentences, part3


def recover(missing, anchors, sentences, methods, loud, get_model):
    """Find items the full transcription missed.

    1. Re-listen to a short clip after each long pause where the item must be.
       Whisper sometimes skips a whole sentence in a full pass - even when that
       stretch is re-run with context around it - yet hears it from a clip on
       its own (DE_01: "Questions 68 through 70"). The clip starts 1 s INTO the
       silence: started right at the speech, Whisper swallowed "Number 21" and
       began at the question; a prompt naming the item made it worse.
    2. Part 1/2 only, if that fails: between items N-1 and N+1 there is exactly
       one answer pause, and item N starts where it ends.
    """
    order = list(PART12_ITEMS) + list(GROUP_FIRSTS)
    for n in missing:
        lo = max((anchors[k] for k in order if k < n and k in anchors), default=0.0)
        hi = min((anchors[k] for k in order if k > n and k in anchors), default=float("inf"))
        cands = [p for p in loud.pauses if lo + 1 < p[1] < hi - 1 and p[1] - p[0] >= MIN_PAUSE]
        cands.sort(key=lambda p: p[1] - p[0], reverse=True)  # answer pauses first
        for pause in cands[:12]:
            start = max(pause[0], pause[1] - 1.0)
            clip = loud.audio[int(start * SR):int((start + CLIP_SECONDS) * SR)]
            words = transcribe(get_model(), clip, offset=start)
            for i in range(len(words)):
                if n in PART12_ITEMS and (t := item_at(words, i, n)) is not None:
                    anchors[n] = t
                elif n in GROUP_FIRSTS and (g := group_at(words, i, n)):
                    anchors[n], sentences[n] = g
                else:
                    continue
                methods[n] = "nghe lại đoạn ngắn"
                break
            if n in anchors:
                break
        if n not in anchors and n in PART12_ITEMS:
            answer_pauses = [p for p in cands if p[1] - p[0] >= ANSWER_PAUSE]
            if len(answer_pauses) == 1:
                anchors[n] = answer_pauses[0][1] + 0.3
                methods[n] = "suy từ cấu trúc (khoảng trả lời duy nhất giữa hai câu kề)"
        print(f"  {'câu' if n < 32 else 'nhóm'} {n}: {methods.get(n, 'KHÔNG tìm được')}")


def mmss(seconds):
    s = max(0, math.floor(seconds))
    return f"{s // 60}:{s % 60:02d}"


def main():
    ap = argparse.ArgumentParser(description="Dò mốc audio_start cho phần Listening của một đề TOEIC.")
    ap.add_argument("--audio", required=True, help="file nghe (.mp3) của cả phần Listening")
    ap.add_argument("--out", help="file kết quả JSON (mặc định: <tên file nghe>.marks.json ở thư mục đề)")
    ap.add_argument("--model", default="small.en", help="model Whisper (mặc định small.en)")
    ap.add_argument("--device", default="auto", choices=["auto", "cuda", "cpu"])
    args = ap.parse_args()

    audio_path = os.path.abspath(args.audio)
    # Default beside the workbook, not in media/: everything in media/ is
    # scanned by the importer and would ride along in the upload zip.
    folder = os.path.dirname(audio_path)
    if os.path.basename(folder).lower() == "media":
        folder = os.path.dirname(folder)
    stem = os.path.splitext(os.path.basename(audio_path))[0]
    out = os.path.abspath(args.out or os.path.join(folder, stem + ".marks.json"))
    cache = os.path.splitext(out)[0] + ".words.json"

    model = None

    def get_model():
        nonlocal model
        if model is None:
            model = load_model(args.model, args.device)
        return model

    print(f"Giải mã {audio_path}…")
    audio = decode_audio(audio_path, sampling_rate=SR)
    loud = Loudness(audio)
    words = cached_words(cache, audio_path, args.model, get_model, audio)

    anchors, sentences, _ = find_anchors(words)
    methods = {n: "whisper" for n in anchors}
    missing = [n for n in list(PART12_ITEMS) + list(GROUP_FIRSTS) if n not in anchors]
    if missing:
        print(f"Bản nhận dạng thiếu {missing}, tìm lại từng mục…")
        recover(missing, anchors, sentences, methods, loud, get_model)

    marks, titles, rows, problems = {}, {}, [], []
    for n in sorted(anchors):
        pause = loud.onset_before(anchors[n])
        if pause is None:
            problems.append(f"{n}: không thấy khoảng lặng nào trước tiếng đọc")
            continue
        onset = pause[1]
        mark = mmss(onset - LEAD)
        m = math.floor(onset - LEAD)
        for q in ([n] if n < 32 else range(n, n + 3)):
            marks[str(q)] = mark
        if n >= 32:
            titles[str(n)] = title_from(sentences[n], n)

        # Checks: the mark sits in silence, speech follows promptly, and every
        # item but the first of a part follows a full answer pause.
        speech = loud.first_speech_after(m, MAX_ONSET_DELAY)
        issues = []
        if loud.level(m, m + 0.1) > -45:
            issues.append("mốc không rơi vào khoảng lặng")
        if speech is None:
            issues.append(f"không có tiếng đọc trong {MAX_ONSET_DELAY}s sau mốc")
        if n not in FIRST_OF_PART and pause[1] - pause[0] < ANSWER_PAUSE:
            issues.append(f"khoảng lặng trước chỉ {pause[1] - pause[0]:.1f}s, không giống thời gian trả lời")
        # The announcement takes a second or so to reach the digit. Much more
        # means the mark settled on an earlier pause than the item's own.
        if anchors[n] - onset > MAX_ANCHOR_LAG:
            issues.append(f"tiếng đọc bắt đầu {anchors[n] - onset:.1f}s trước chỗ nghe thấy số câu, "
                          "có thể đã bám nhầm khoảng lặng trước đó")
        problems += [f"{n}: {x}" for x in issues]
        note = "" if methods[n] == "whisper" else f"  [{methods[n]}]"
        note += "  <-- " + "; ".join(issues) if issues else ""
        rows.append((n, mark, onset, anchors[n], pause[1] - pause[0], note))

    seq = [marks.get(str(n)) for n in range(1, 101)]
    if None in seq:
        problems.append("thiếu mốc cho câu " + ", ".join(str(n) for n in range(1, 101) if str(n) not in marks))
    else:
        secs = [int(v.split(":")[0]) * 60 + int(v.split(":")[1]) for v in seq]
        if any(a > b for a, b in zip(secs, secs[1:])):
            problems.append("các mốc không tăng dần")

    print(f"\n{'câu':>4} {'mốc':>6}   {'tiếng đọc':>9}  {'số câu':>8}  {'lặng trước':>10}")
    for n, mark, onset, anchor, pause_len, note in rows:
        print(f"{n:>4} {mark:>6}   {onset:8.2f}s  {anchor:7.2f}s  {pause_len:9.1f}s{note}")

    with open(out, "w", encoding="utf8") as fh:
        json.dump({
            "audio": os.path.basename(audio_path),
            "words_cache": cache,
            "marks": marks,
            "titles": titles,
            "found_by": {str(n): m for n, m in methods.items() if m != "whisper"},
            "problems": problems,
        }, fh, ensure_ascii=False, indent=1)

    print(f"\n{len(marks)}/100 câu có mốc, {len(titles)}/23 tiêu đề nhóm. Kết quả: {out}")
    if problems:
        print("CẦN XEM LẠI:\n  " + "\n  ".join(problems))
        sys.exit(1)
    print("Mọi mốc đều qua kiểm tra. Bước tiếp: php local/quizportal/cli/apply_audio_marks.php "
          f"--file=<đề>.xlsx --marks=\"{out}\"")


if __name__ == "__main__":
    main()
