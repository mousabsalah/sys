#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
syst-engine/solver.py — محرك الاقتراح التلقائي (Google OR-Tools CP-SAT)

يستقبل JSON على stdin:
{
  "group":   {"id": 5, "student_count": 56},
  "courses": [{"course_id": 1, "code": "CS101", "name": "...", "sessions": 2,
               "faculty_pool": [{"id": 3, "full_name": "..."}]}],
  "periods": [{"period_id": 1, "day": "الأحد", "start_time": "07:00:00",
               "end_time": "09:00:00", "label": "الفترة الأولى"}],
  "halls":   [{"id": 6, "name": "قاعة 6", "hall_type": "قاعة", "capacity": 40}],
  "existing":[{"day_of_week": "الأحد", "start_time": "09:00:00", "end_time": "11:00:00",
               "hall_id": 1, "faculty_id": 3, "group_id": 5, "kind": "lecture"}],
  "academic_term": "2026/1"
}

ويُعيد JSON على stdout:
{"ok": true, "status": "OPTIMAL|FEASIBLE", "placed": int, "assignments": [...],
 "unplaced": [{"course_id": 1, "sessions": 2, "reason": "..."}]}

النموذج: x[c,p,h,f] ∈ {0,1}
  - القيود الصلبة: حصة الشعبة ≤ محاضرة واحدة لكل فترة، القاعة والمدرس لا يُسجّلان مرتين
    في الفترة نفسها، سعة القاعة ≥ عدد الطلاب، تجاهل القاعات/الفترات المحجوزة موجودًا.
  - الهدف: أقصى عدد محاضرات موزعة + توازن أيام الأسبوع (min max عدد حصص في اليوم).
"""

import json
import sys

# Windows pipes may otherwise default to cp1256/cp437 — forcer UTF-8 للعربية
try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass

from ortools.sat.python import cp_model


def fail(code: str, message: str):
    json.dump({"ok": False, "error": f"{code}: {message}"}, sys.stdout, ensure_ascii=False)
    sys.exit(1)


def main():
    raw = sys.stdin.read()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail("BAD_INPUT", f"Invalid JSON payload ({exc})")

    group = data.get("group") or {}
    group_id = int(group.get("id") or 0)
    students = int(group.get("student_count") or 0)
    courses = data.get("courses") or []
    halls = data.get("halls") or []
    periods = data.get("periods") or []
    existing = data.get("existing") or []
    term = data.get("academic_term") or ""

    if group_id <= 0:
        fail("BAD_INPUT", "group.id مطلوب")

    # ── الموجود: ما لا يجوز تخطيه ─────────────────────────────────────────
    group_busy = {(e["day_of_week"], e["start_time"]) for e in existing if int(e.get("group_id") or 0) == group_id}
    hall_busy = {(int(e.get("hall_id") or 0), e["day_of_week"], e["start_time"]) for e in existing}
    fac_busy = {(int(e.get("faculty_id") or 0), e["day_of_week"], e["start_time"]) for e in existing}

    model = cp_model.CpModel()

    xs = []          # (var, ci, pi, hi, fi)
    course_vars = {}  # ci -> [vars]
    period_vars = {}  # pi -> [vars]
    hall_period_vars = {}  # (hi, pi) -> [vars]
    fac_period_vars = {}   # (fi, pi) -> [vars]
    day_vars = {}    # day -> [vars]
    course_fail_reason = {}

    for ci, c in enumerate(courses):
        sessions = int(c.get("sessions") or 1)
        pool = c.get("faculty_pool") or []
        pool_ids = []
        for f in pool:
            fid = int(f["id"]) if isinstance(f, dict) else int(f)
            name = f.get("full_name") if isinstance(f, dict) else str(f)
            pool_ids.append((fid, name))
        if not pool_ids:
            course_fail_reason[ci] = "لا يوجد مدرسون مسندون لهذا المقرر في pool (course_faculty)"
            course_vars[ci] = []
            continue

        cvars = []
        for pi, p in enumerate(periods):
            if (p["day"], p["start_time"]) in group_busy:
                continue
            for hi, h in enumerate(halls):
                if int(h.get("capacity") or 0) < students:
                    continue
                if (int(h["id"]), p["day"], p["start_time"]) in hall_busy:
                    continue
                for fi, (fid, _fname) in enumerate(pool_ids):
                    if (fid, p["day"], p["start_time"]) in fac_busy:
                        continue
                    var = model.NewBoolVar(f"x_{ci}_{pi}_{hi}_{fi}")
                    xs.append((var, ci, pi, hi, fi))
                    cvars.append(var)
                    period_vars.setdefault(pi, []).append(var)
                    hall_period_vars.setdefault((hi, pi), []).append(var)
                    fac_period_vars.setdefault((fid, p["day"], p["start_time"]), []).append(var)
                    day_vars.setdefault(p["day"], []).append(var)

        if not cvars:
            course_fail_reason[ci] = "لا توجد (فترة × قاعة × مدرّس) متوافقة — القاعات ممتلئة أو السعات غير كافية أو المدرسون مشغولون"
        course_vars[ci] = cvars

    # ── القيود الصلبة ──────────────────────────────────────────────────────
    for ci, cvars in course_vars.items():
        if cvars:
            model.Add(sum(cvars) <= int(courses[ci].get("sessions") or 1))

    for pi, pv in period_vars.items():
        model.Add(sum(pv) <= 1)  # الشعبة: محاضرة واحدة في الفترة

    for (hi, pi), hv in hall_period_vars.items():
        model.Add(sum(hv) <= 1)  # القاعة: محاضرة واحدة

    for _fac_key, fv in fac_period_vars.items():
        model.Add(sum(fv) <= 1)  # المدرس: مكان واحد في الفترة

    # ── الهدف: أكبر تغطية + توازن أيام الأسبوع ──────────────────────────────
    if not xs:
        assignments = []
        unplaced = [
            {"course_id": int(c["course_id"]), "code": c.get("code", ""), "name": c.get("name", ""),
             "sessions": int(c.get("sessions") or 1), "reason": course_fail_reason.get(i, "لا توجد متغيرات قرار متاحة")}
            for i, c in enumerate(courses)
        ]
        json.dump({"ok": True, "status": "NO_SOLUTION", "placed": 0,
                   "assignments": assignments, "unplaced": unplaced,
                   "academic_term": term}, sys.stdout, ensure_ascii=False)
        return

    all_vars = [v for v, *_ in xs]
    placed = sum(all_vars)

    day_loads = []
    for day, dvs in day_vars.items():
        load = model.NewIntVar(0, len(dvs), f"load_{day}")
        model.Add(load == sum(dvs))
        day_loads.append(load)

    max_load = model.NewIntVar(0, len(all_vars) + 1, "max_load")
    for load in day_loads:
        model.Add(max_load >= load)

    model.Maximize(100 * placed - 2 * max_load)

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 20.0
    solver.parameters.num_workers = 8
    solver.parameters.log_search_progress = False

    status = solver.Solve(model)
    status_name = solver.StatusName(status)

    placed_count = int(solver.Value(placed)) if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else 0

    assignments = []
    course_placed = {ci: 0 for ci in range(len(courses))}
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for var, ci, pi, hi, fi in xs:
            if solver.Value(var) == 1:
                p = periods[pi]
                h = halls[hi]
                pool = courses[ci].get("faculty_pool") or []
                f = pool[fi]
                fid = int(f["id"]) if isinstance(f, dict) else int(f)
                fname = f.get("full_name") if isinstance(f, dict) else str(f)
                assignments.append({
                    "course_id": int(courses[ci]["course_id"]),
                    "code": courses[ci].get("code", ""),
                    "name": courses[ci].get("name", ""),
                    "period_id": int(p["period_id"]),
                    "day": p["day"],
                    "start_time": p["start_time"],
                    "end_time": p["end_time"],
                    "label": p.get("label", ""),
                    "hall_id": int(h["id"]),
                    "hall_name": h.get("name", ""),
                    "faculty_id": fid,
                    "faculty_name": fname,
                    "academic_term": term,
                })
                course_placed[ci] += 1

    unplaced = []
    for ci, c in enumerate(courses):
        needed = int(c.get("sessions") or 1)
        missing = needed - course_placed.get(ci, 0)
        if missing > 0:
            unplaced.append({
                "course_id": int(c["course_id"]), "code": c.get("code", ""), "name": c.get("name", ""),
                "sessions": missing,
                "reason": course_fail_reason.get(ci, "تعذّر التوظيف ضمن القيود الصلبة — وسّع السعة أو الفترات"),
            })

    assignments.sort(key=lambda a: (a["day"], a["start_time"]))

    json.dump({
        "ok": True,
        "status": status_name,
        "placed": placed_count,
        "assignments": assignments,
        "unplaced": unplaced,
        "academic_term": term,
    }, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
