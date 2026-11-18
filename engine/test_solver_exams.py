# -*- coding: utf-8 -*-
"""
اختبارات CP-SAT micro-instances لمحرك الاقتراح (solver_exams.py — الامتحانات).

تُشغَّل من مجلد engine/:  python -m pytest test_solver_exams.py -v
"""

from __future__ import annotations

import copy
import io
import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).parent))
import solver_exams  # noqa: E402


def base_payload() -> dict:
    return {
        "group": {"id": 1, "student_count": 30},
        "courses": [
            {"course_id": 1, "code": "CS101", "name": "برمجة", "sessions": 1},
            {"course_id": 2, "code": "MATH101", "name": "رياضيات", "sessions": 1},
        ],
        "exam_slots": [
            {"slot_id": 1, "date": "2026-12-20", "day": "الأحد", "start_time": "09:00:00", "end_time": "12:00:00", "label": "صباحًا"},
            {"slot_id": 2, "date": "2026-12-20", "day": "الأحد", "start_time": "12:30:00", "end_time": "15:30:00", "label": "مساءً"},
            {"slot_id": 3, "date": "2026-12-21", "day": "الاثنين", "start_time": "09:00:00", "end_time": "12:00:00", "label": "صباحًا"},
        ],
        "halls": [{"id": 1, "name": "قاعة 1", "capacity": 40}],
        "existing": [],
        "academic_term": "2026/1",
        "timeout_seconds": 10,
        "random_seed": 42,
    }


def run_solver(payload: dict) -> dict:
    buf = io.StringIO()
    old_stdout = sys.stdout
    sys.stdout = buf
    try:
        solver_exams.solve(copy.deepcopy(payload))
    finally:
        sys.stdout = old_stdout
    return json.loads(buf.getvalue())


def test_all_courses_placed_on_open_grid():
    out = run_solver(base_payload())
    assert out["ok"] is True
    assert out["status"] in ("OPTIMAL", "FEASIBLE")
    assert out["placed"] == 2
    assert len(out["assignments"]) == 2
    # لا تاريخ/قاعة مكرر لنفس الشعبة (لا يجوز لامتحانين بفارغ)
    keys = {(a["exam_date"], a["start_time"]) for a in out["assignments"]}
    assert len(keys) == 2


def test_lecture_conflict_pushes_course_away():
    payload = base_payload()
    payload["existing"] = [
        {"day_of_week": "الأحد", "start_time": "09:00:00", "end_time": "12:00:00",
         "hall_id": 1, "group_id": 1, "kind": "lecture"},
    ]
    out = run_solver(payload)
    assert out["placed"] == 2
    for a in out["assignments"]:
        assert not (a["exam_date"] == "2026-12-20" and a["start_time"] == "09:00:00")


def test_full_hall_leaves_course_unplaced():
    payload = base_payload()
    payload["halls"] = [{"id": 1, "name": "قاعة 1", "capacity": 20}]  # أصغر من الشعبة — لا قاعة صالحة
    out = run_solver(payload)
    assert out["placed"] == 0
    assert len(out["unplaced"]) == 2


def test_wrong_shape_fails_gracefully():
    buf = io.StringIO()
    old_stdout = sys.stdout
    sys.stdout = buf
    try:
        with pytest.raises(SystemExit):
            solver_exams.solve({"group": {"id": 0}, "courses": [], "exam_slots": [], "halls": []})
    finally:
        sys.stdout = old_stdout
    out = json.loads(buf.getvalue())
    assert out["ok"] is False
    assert "BAD_INPUT" in out["error"] or "MISSING" in out["error"]
