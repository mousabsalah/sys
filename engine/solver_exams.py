#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
syst-engine/solver_exams.py — محرك «اقتراح الامتحانات التلقائي» (Google OR-Tools CP-SAT)

يستقبل JSON على stdin:
{
  "group":   {"id": 5, "student_count": 40},
  "courses": [{"course_id": 1, "code": "CS101", "name": "...", "sessions": 1}],
  "exam_slots": [{"slot_id": 1, "date": "2026-12-20", "day": "الأحد",
                  "start_time": "09:00:00", "end_time": "12:00:00",
                  "label": "صباحًا"}],
  "halls":   [{"id": 6, "name": "قاعة 6", "hall_type": "قاعة", "capacity": 40}],
  "existing":[{"day_of_week": "الأحد", "exam_date": "2026-12-20",
               "start_time": "09:00:00", "end_time": "12:00:00",
               "hall_id": 1, "group_id": 5, "kind": "exam"}],
  "academic_term": "2026/1",
  "timeout_seconds": 20,      // اختياري — يُقيَّد بـ [1, 120]
  "num_workers": 8,           // اختياري — يُقيَّد بـ [1, 16]
  "random_seed": 42           // اختياري — للتكرارية (default 42)
}

ويُعيد JSON على stdout:
{"ok": true, "status": "OPTIMAL|FEASIBLE|NO_SOLUTION", "placed": int,
 "assignments": [{"course_id": 1, "exam_date": "2026-12-20", ...}],
 "unplaced":    [{"course_id": 1, "sessions": 1, "reason": "..."}],
 "academic_term": "2026/1"}

النموذج الرياضي: x[c,s,h] ∈ {0,1} — امتحان واحد لكل مقرر (فاينل).
القيود الصلبة: قاعة بامتحان واحد في نفس الوقت؛ الشعبة بفاينل واحد في نفس الوقت؛
سعة القاعة ≥ عدد طلاب الشعبة؛ تجاهل المتصادم مع موجود (محاضرة/امتحان/حجز).
الهدف: أقصى عدد امتحانات موزعة.
"""

from __future__ import annotations

import json
import logging
import sys
from dataclasses import dataclass
from typing import Any

# Windows pipes may otherwise default to cp1256/cp437 — force UTF-8 للعربية
try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass

logging.basicConfig(stream=sys.stderr, level=logging.INFO, format="[solver-exams] %(levelname)s %(message)s")
log = logging.getLogger("syst-solver-exams")

from ortools.sat.python import cp_model  # noqa: E402  (بعد reconfigure عمدًا)

W_PLACEMENT = 100

DEFAULT_TIMEOUT = 20.0
MAX_TIMEOUT = 120.0
DEFAULT_WORKERS = 8
MAX_WORKERS = 16
DEFAULT_SEED = 42


def fail(code: str, message: str) -> None:
    """اكتب خطأً موحدًا واخرج بكود 1 — Go يقرأ ok:false ويعرض الرسالة عربية."""
    json.dump({"ok": False, "error": f"{code}: {message}"}, sys.stdout, ensure_ascii=False)
    sys.exit(1)


def _to_int(value: Any, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _to_float(value: Any, default: float) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _clamp(value: float, low: float, high: float) -> float:
    return max(low, min(high, value))


WEEKDAY_AR = {0: "الاثنين", 1: "الثلاثاء", 2: "الأربعاء", 3: "الخميس", 4: "الجمعة", 5: "السبت", 6: "الأحد"}


def arabic_weekday(iso_date: str) -> str:
    """حوّل تاريخ ISO (YYYY-MM-DD) لاسم يوم عربي — نفس الجدول المستخدم في Go."""
    try:
        import datetime as _dt

        parsed = _dt.date.fromisoformat(str(iso_date)[:10])
        return WEEKDAY_AR.get(parsed.weekday(), "")
    except Exception:
        return ""


def overlaps(a_start: str, a_end: str, b_start: str, b_end: str) -> bool:
    return a_start < b_end and b_start < a_end


def same_slot(slot_for: "ExamSlot", existing_day: str, existing_exam_date: str, existing_start: str, existing_end: str) -> bool:
    """هل يتعارض الموعد s مع سطر موجود (محاضرة=weekday، امتحان/حجز=date)؟"""
    slot_day = slot_for.day or arabic_weekday(slot_for.date)
    if existing_exam_date:
        return existing_exam_date == slot_for.date and overlaps(slot_for.start_time, slot_for.end_time, existing_start, existing_end)
    if existing_day:
        return existing_day == slot_day and overlaps(slot_for.start_time, slot_for.end_time, existing_start, existing_end)
    return False


@dataclass(frozen=True)
class Group:
    id: int
    student_count: int


@dataclass(frozen=True)
class Course:
    course_id: int
    code: str
    name: str
    sessions: int  # فاينل واحد لكل مقرر — sessions محفوظة للعرض


@dataclass(frozen=True)
class Hall:
    id: int
    name: str
    capacity: int


@dataclass(frozen=True)
class ExamSlot:
    slot_id: int
    date: str
    day: str
    start_time: str
    end_time: str
    label: str


@dataclass(frozen=True)
class ExistingSlot:
    day_of_week: str
    exam_date: str
    start_time: str
    end_time: str
    hall_id: int
    group_id: int
    kind: str


@dataclass
class ParsedInput:
    group: Group
    courses: list[Course]
    halls: list[Hall]
    slots: list[ExamSlot]
    existing: list[ExistingSlot]
    term: str
    timeout: float
    workers: int
    seed: int


def parse_payload(data: dict) -> ParsedInput:
    group_raw = data.get("group") or {}
    group_id = _to_int(group_raw.get("id"))
    if group_id <= 0:
        fail("BAD_INPUT", "group.id مطلوب")

    group = Group(id=group_id, student_count=max(0, _to_int(group_raw.get("student_count"))))

    courses: list[Course] = []
    for i, c in enumerate(data.get("courses") or []):
        if not isinstance(c, dict):
            log.warning("course #%d غير صالح (ليس كائن) — يُتجاهل", i)
            continue
        course_id = _to_int(c.get("course_id"))
        if course_id <= 0:
            log.warning("course #%d بلا course_id صالح — يُتجاهل", i)
            continue
        courses.append(Course(
            course_id=course_id,
            code=str(c.get("code") or ""),
            name=str(c.get("name") or ""),
            sessions=1,
        ))

    halls: list[Hall] = []
    for h in data.get("halls") or []:
        if not isinstance(h, dict):
            continue
        hall_id = _to_int(h.get("id"))
        capacity = _to_int(h.get("capacity"))
        if hall_id <= 0 or capacity <= 0:
            log.warning("قاعة غير صالحة (id=%s capacity=%s) — تُتجاهل", h.get("id"), h.get("capacity"))
            continue
        halls.append(Hall(id=hall_id, name=str(h.get("name") or ""), capacity=capacity))

    slots: list[ExamSlot] = []
    for p in data.get("exam_slots") or []:
        if not isinstance(p, dict):
            continue
        date = str(p.get("date") or "").strip()
        start = str(p.get("start_time") or "")
        end = str(p.get("end_time") or "")
        if not date or not start or not end:
            log.warning("فترة امتحان ناقصة (date/start/end) — تُتجاهل: %s", p)
            continue
        day = str(p.get("day") or arabic_weekday(date))
        slots.append(ExamSlot(
            slot_id=_to_int(p.get("slot_id")),
            date=date,
            day=day,
            start_time=start,
            end_time=end,
            label=str(p.get("label") or ""),
        ))

    existing: list[ExistingSlot] = []
    for e in data.get("existing") or []:
        if not isinstance(e, dict):
            continue
        existing.append(ExistingSlot(
            day_of_week=str(e.get("day_of_week") or ""),
            exam_date=str(e.get("exam_date") or ""),
            start_time=str(e.get("start_time") or ""),
            end_time=str(e.get("end_time") or ""),
            hall_id=_to_int(e.get("hall_id")),
            group_id=_to_int(e.get("group_id")),
            kind=str(e.get("kind") or "exam"),
        ))

    if not slots:
        fail("MISSING_EXAM_SLOTS", "لا توجد فترات امتحان في المدى المحدد — وسّع التاريخ أو شغّل «فتح السنة الدراسية».")

    timeout = _clamp(_to_float(data.get("timeout_seconds"), DEFAULT_TIMEOUT), 1.0, MAX_TIMEOUT)
    workers = int(_clamp(_to_int(data.get("num_workers"), DEFAULT_WORKERS), 1, MAX_WORKERS))
    seed = _to_int(data.get("random_seed"), DEFAULT_SEED)

    return ParsedInput(
        group=group, courses=courses, halls=halls, slots=slots,
        existing=existing, term=str(data.get("academic_term") or ""),
        timeout=timeout, workers=workers, seed=seed,
    )


# ── بناء النموذج والتشغيل ───────────────────────────────────────────────────

def solve(data: dict) -> None:
    inp = parse_payload(data)

    log.info(
        "solve: group=%d courses=%d slots=%d halls=%d existing=%d term=%s timeout=%.1fs",
        inp.group.id, len(inp.courses), len(inp.slots), len(inp.halls), len(inp.existing), inp.term or "-", inp.timeout,
    )

    if not inp.courses:
        json.dump({"ok": True, "status": "NO_COURSES", "placed": 0, "assignments": [], "unplaced": [], "academic_term": inp.term},
                  sys.stdout, ensure_ascii=False)
        return

    # الموجود القاتل: قاعة بامتحان/محاضرة/حجز متداخل، أو الشعبة في موعد متداخل
    def hall_busy(hall_id: int, slot: ExamSlot) -> bool:
        for e in inp.existing:
            if e.hall_id == hall_id and same_slot(slot, e.day_of_week, e.exam_date, e.start_time, e.end_time):
                return True
        return False

    def group_busy(slot: ExamSlot) -> bool:
        for e in inp.existing:
            if e.group_id == inp.group.id and same_slot(slot, e.day_of_week, e.exam_date, e.start_time, e.end_time):
                return True
        return False

    model = cp_model.CpModel()
    xs: list[tuple[cp_model.IntVar, int, int, int]] = []  # (var, ci, si, hi)
    course_vars: dict[int, list[cp_model.IntVar]] = {}
    course_fail_reason: dict[int, str] = {}

    for ci, course in enumerate(inp.courses):
        cvars: list[cp_model.IntVar] = []
        for si, slot in enumerate(inp.slots):
            if group_busy(slot):
                continue
            for hi, hall in enumerate(inp.halls):
                if hall.capacity < inp.group.student_count:
                    continue
                if hall_busy(hall.id, slot):
                    continue
                var = model.NewBoolVar(f"x_{ci}_{si}_{hi}")
                xs.append((var, ci, si, hi))
                cvars.append(var)
        if not cvars:
            course_fail_reason[ci] = (
                "لا توجد (تاريخ × قاعة) متوافقة — القاعات ممتلئة أو سعاتها غير كافية أو الشعبة مشغولة في كل المدى"
            )
        course_vars[ci] = cvars

    # امتحان واحد لكل مقرّر فاينل
    for ci, cvars in course_vars.items():
        if cvars:
            model.Add(sum(cvars) <= 1)

    # الشعبة: امتحان واحد في نفس الفترة
    slot_group_vars: dict[int, list[cp_model.IntVar]] = {}
    for var, ci, si, hi in xs:
        slot_group_vars.setdefault(si, []).append(var)
    for si, svars in slot_group_vars.items():
        if len(svars) > 1:
            model.Add(sum(svars) <= 1)

    # القاعة: امتحان واحد في نفس الفترة
    hall_slot_vars: dict[tuple[int, int], list[cp_model.IntVar]] = {}
    for var, ci, si, hi in xs:
        hall_slot_vars.setdefault((hi, si), []).append(var)
    for hvars in hall_slot_vars.values():
        if len(hvars) > 1:
            model.Add(sum(hvars) <= 1)

    if not xs:
        json.dump({
            "ok": True,
            "status": "NO_SOLUTION",
            "placed": 0,
            "assignments": [],
            "unplaced": [{
                "course_id": c.course_id,
                "code": c.code,
                "name": c.name,
                "sessions": 1,
                "reason": "لا توجد (تاريخ × قاعة) متوافقة — القاعات ممتلئة أو السعات غير كافية أو الشعبة مشغولة في كل المدى",
            } for c in inp.courses],
            "academic_term": inp.term,
        }, sys.stdout, ensure_ascii=False)
        return

    placed = sum(var for var, *_ in xs)
    model.Maximize(W_PLACEMENT * placed)

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = inp.timeout
    solver.parameters.num_workers = inp.workers
    solver.parameters.random_seed = inp.seed
    solver.parameters.log_search_progress = False

    status = solver.Solve(model)
    status_name = solver.StatusName(status)
    log.info("status=%s wall=%.2fs", status_name, solver.WallTime())

    ok_status = status in (cp_model.OPTIMAL, cp_model.FEASIBLE)
    assignments: list[dict] = []
    placed_values: dict[int, int] = {i: 0 for i in range(len(inp.courses))}
    placed_count = 0

    if ok_status:
        for var, ci, si, hi in xs:
            if solver.Value(var) != 1:
                continue
            placed_count += 1
            placed_values[ci] += 1
            slot = inp.slots[si]
            hall = inp.halls[hi]
            course = inp.courses[ci]
            assignments.append({
                "course_id": course.course_id,
                "code": course.code,
                "name": course.name,
                "period_id": slot.slot_id,
                "exam_date": slot.date,
                "day": slot.day,
                "start_time": slot.start_time,
                "end_time": slot.end_time,
                "label": slot.label,
                "hall_id": hall.id,
                "hall_name": hall.name,
                "academic_term": inp.term,
                "exam_type": "فاينل",
            })

    assignments.sort(key=lambda a: (a["exam_date"], a["start_time"]))

    unplaced: list[dict] = []
    for ci, course in enumerate(inp.courses):
        if placed_values.get(ci, 0) == 0:
            unplaced.append({
                "course_id": course.course_id,
                "code": course.code,
                "name": course.name,
                "sessions": 1,
                "reason": course_fail_reason.get(ci, "تعذّر توزيع الفاينل على مدى التواريخ"),
            })

    json.dump({
        "ok": True,
        "status": status_name if ok_status else "NO_SOLUTION",
        "placed": placed_count,
        "assignments": assignments,
        "unplaced": unplaced,
        "academic_term": inp.term,
    }, sys.stdout, ensure_ascii=False)


def main() -> None:
    raw = sys.stdin.read()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail("BAD_INPUT", f"Invalid JSON payload ({exc})")
    if not isinstance(data, dict):
        fail("BAD_INPUT", "payload must be a JSON object")
    try:
        solve(data)
    except SystemExit:
        raise
    except Exception as exc:
        log.exception("unexpected solver failure")
        fail("BAD_INPUT", f"unexpected error ({exc.__class__.__name__}): {exc}")


if __name__ == "__main__":
    main()
