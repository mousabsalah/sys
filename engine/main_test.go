package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestOverlaps(t *testing.T) {
	cases := []struct {
		aStart, aEnd, bStart, bEnd string
		want                      bool
	}{
		{"07:00:00", "09:00:00", "07:00:00", "09:00:00", true},  // identical
		{"07:00:00", "09:00:00", "08:00:00", "10:00:00", true},  // partial overlap
		{"07:00:00", "09:00:00", "09:00:00", "11:00:00", false}, // back-to-back — لا تتعارض
		{"09:00:00", "11:00:00", "07:00:00", "09:00:00", false}, // back-to-back معكوس
		{"07:00:00", "09:00:00", "10:00:00", "12:00:00", false}, // منفصل تمامًا
	}
	for _, c := range cases {
		if got := overlaps(c.aStart, c.aEnd, c.bStart, c.bEnd); got != c.want {
			t.Errorf("overlaps(%s,%s,%s,%s) = %v, want %v", c.aStart, c.aEnd, c.bStart, c.bEnd, got, c.want)
		}
	}
}

func TestResolveDayOfWeekFromDateString(t *testing.T) {
	cases := []struct {
		in   string
		want string
	}{
		{"2026-11-14", "السبت"},
		{"2026-11-15", "الأحد"},
		{"2026-11-20", "الجمعة"},
		{"bad-date", ""},
		{"", ""},
		{"2026-13-99", ""},
	}
	for _, c := range cases {
		if got := resolveDayOfWeekFromDateString(c.in); got != c.want {
			t.Errorf("resolveDayOfWeekFromDateString(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

func TestSamePeriod(t *testing.T) {
	lecture := func(day string) Payload {
		return Payload{Type: "lecture", DayOfWeek: day, StartTime: "07:00:00", EndTime: "09:00:00", HallID: 1, FacultyID: 1, GroupID: 1, CourseID: 1}
	}
	lectureSlot := func(day string, facID int, hallID int) ExistingSlot {
		return ExistingSlot{ID: 1, Kind: "lecture", DayOfWeek: day, StartTime: "07:00:00", EndTime: "09:00:00", HallID: hallID, FacultyID: facID, GroupID: 1, CourseID: 1}
	}
	exam := func(date string) Payload {
		return Payload{Type: "exam", ExamDate: date, StartTime: "07:00:00", EndTime: "09:00:00", HallID: 1, FacultyID: 1, GroupID: 1, CourseID: 1}
	}
	examSlot := func(date string) ExistingSlot {
		return ExistingSlot{ID: 1, Kind: "exam", ExamDate: date, StartTime: "07:00:00", EndTime: "09:00:00", HallID: 1, FacultyID: 1, GroupID: 1, CourseID: 1}
	}

	if !samePeriod(lecture("الأحد"), lectureSlot("الأحد", 1, 1)) {
		t.Error("same day same time lecture should conflict")
	}
	if samePeriod(lecture("الأحد"), lectureSlot("الاثنين", 1, 1)) {
		t.Error("different day lecture should not conflict")
	}
	if !samePeriod(exam("2026-11-14"), examSlot("2026-11-14")) {
		t.Error("same exam date overlapping should conflict")
	}
	if samePeriod(exam("2026-11-14"), examSlot("2026-11-15")) {
		t.Error("different exam dates should not conflict")
	}
	// slot without day_of_week but with exam_date — يُحل عبر التاريخ
	slot := ExistingSlot{ID: 9, Kind: "booking", ExamDate: "2026-11-14", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 1}
	if !samePeriod(lecture("السبت"), slot) {
		t.Error("slot resolved through exam_date should conflict with Saturday lecture")
	}
}

func TestCheckCleanLecture(t *testing.T) {
	p := Payload{
		Type: "lecture", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00",
		HallID: 1, FacultyID: 1, GroupID: 1, CourseID: 1, HallStatus: "متاحة",
	}
	result := check(p)
	if !result.Ok || result.Conflict {
		t.Errorf("clean lecture should pass, got conflict=%v violations=%v", result.Conflict, result.Violations)
	}
}

func TestCheckViolations(t *testing.T) {
	intPtr := func(v int) *int { return &v }
	boolPtr := func(v bool) *bool { return &v }

	cases := []struct {
		name     string
		mutate   func(Payload) Payload
		code     string
		hard     bool
	}{
		{
			name:   "exam without date",
			mutate: func(p Payload) Payload { p.Type = "exam"; p.ExamDate = ""; return p },
			code:   "MISSING_EXAM_DATE", hard: true,
		},
		{
			name:   "lecture without day and without date",
			mutate: func(p Payload) Payload { p.DayOfWeek = ""; p.ExamDate = ""; return p },
			code:   "MISSING_DAY_OF_WEEK", hard: true,
		},
		{
			name:   "end before start",
			mutate: func(p Payload) Payload { p.StartTime = "09:00:00"; p.EndTime = "07:00:00"; return p },
			code:   "INVALID_TIME_RANGE", hard: true,
		},
		{
			name:   "hall under maintenance",
			mutate: func(p Payload) Payload { p.HallStatus = "صيانة"; return p },
			code:   "HALL_UNAVAILABLE", hard: true,
		},
		{
			name:   "lecturer not assigned to course",
			mutate: func(p Payload) Payload { p.Eligible = boolPtr(false); return p },
			code:   "FACULTY_NOT_ELIGIBLE", hard: true,
		},
		{
			name:   "hall capacity too small",
			mutate: func(p Payload) Payload { p.HallCapacity = intPtr(30); p.GroupStudentCount = intPtr(40); return p },
			code:   "HALL_CAPACITY", hard: true,
		},
		{
			name: "hall busy by lecture at same time",
			mutate: func(p Payload) Payload {
				p.Existing = []ExistingSlot{{ID: 1, Kind: "lecture", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 1, FacultyID: 2, GroupID: 2, CourseID: 2}}
				return p
			},
			code: "HALL_BUSY", hard: true,
		},
		{
			name: "lecturer in two lectures at the same time",
			mutate: func(p Payload) Payload {
				p.Existing = []ExistingSlot{{ID: 1, Kind: "lecture", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 9, FacultyID: 1, GroupID: 2, CourseID: 2}}
				return p
			},
			code: "FACULTY_OVERLAP", hard: true,
		},
		{
			name: "group has two lectures at the same time",
			mutate: func(p Payload) Payload {
				p.Existing = []ExistingSlot{{ID: 1, Kind: "lecture", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 9, FacultyID: 2, GroupID: 1, CourseID: 2}}
				return p
			},
			code: "GROUP_OVERLAP", hard: true,
		},
		{
			name: "group has an exam at the same time",
			mutate: func(p Payload) Payload {
				p.Existing = []ExistingSlot{{ID: 1, Kind: "exam", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 9, FacultyID: 2, GroupID: 1, CourseID: 2}}
				return p
			},
			code: "GROUP_EXAM_CONFLICT", hard: true,
		},
		{
			name: "duplicate exam for same course group and date",
			mutate: func(p Payload) Payload {
				p.Type = "exam"
				p.DayOfWeek = ""
				p.ExamDate = "2026-11-14"
				p.CourseID = 7
				p.GroupID = 3
				p.Existing = []ExistingSlot{{ID: 1, Kind: "exam", ExamDate: "2026-11-14", StartTime: "10:00:00", EndTime: "12:00:00", HallID: 9, FacultyID: 2, GroupID: 3, CourseID: 7}}
				return p
			},
			code: "COURSE_EXAM_DUPLICATE", hard: true,
		},
		{
			name: "exam lecturer overlap is not a lecturer conflict",
			mutate: func(p Payload) Payload {
				p.Existing = []ExistingSlot{{ID: 1, Kind: "exam", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00", HallID: 9, FacultyID: 1, GroupID: 2, CourseID: 2}}
				return p
			},
			code: "", hard: false,
		},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			base := Payload{
				Type: "lecture", DayOfWeek: "الأحد", StartTime: "07:00:00", EndTime: "09:00:00",
				HallID: 1, FacultyID: 1, GroupID: 1, CourseID: 1, HallStatus: "متاحة",
			}
			result := check(c.mutate(base))

			if c.code == "" {
				if result.Conflict {
					t.Fatalf("expected no conflict, got violations %v", result.Violations)
				}
				return
			}
			found := false
			for _, v := range result.Violations {
				if v.Code == c.code {
					found = true
					if (v.Severity == "hard") != c.hard {
						t.Errorf("violation %s severity = %s, want hard=%v", v.Code, v.Severity, c.hard)
					}
					break
				}
			}
			if !found {
				t.Fatalf("expected violation %s, got %v", c.code, result.Violations)
			}
		})
	}
}

func TestCheckRouteIntegration(t *testing.T) {
	server := httptest.NewServer(corsMiddleware(newMux()))
	defer server.Close()

	// health
	resp, err := http.Get(server.URL + "/health")
	if err != nil {
		t.Fatalf("health request failed: %v", err)
	}
	_ = resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("/health status = %d", resp.StatusCode)
	}

	// clean lecture conflict check through the HTTP layer
	payload := map[string]interface{}{
		"type": "lecture", "day_of_week": "الأحد", "start_time": "07:00:00", "end_time": "09:00:00",
		"hall_id": 1, "faculty_id": 1, "group_id": 1, "course_id": 1, "hall_status": "متاحة",
	}
	body, _ := json.Marshal(payload)
	resp, err = http.Post(server.URL+"/check", "application/json", bytes.NewReader(body))
	if err != nil {
		t.Fatalf("/check request failed: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()
	var out CheckResult
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		t.Fatalf("decode /check response: %v", err)
	}
	if resp.StatusCode != http.StatusOK || !out.Ok {
		t.Fatalf("/check returned %d ok=%v violations=%v", resp.StatusCode, out.Ok, out.Violations)
	}

	// garbage JSON → 400 with unified envelope
	resp, err = http.Post(server.URL+"/check", "application/json", bytes.NewReader([]byte("{{{")))
	if err != nil {
		t.Fatalf("bad json request failed: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()
	var bad map[string]string
	if err := json.NewDecoder(resp.Body).Decode(&bad); err != nil {
		t.Fatalf("decode bad json response: %v", err)
	}
	if resp.StatusCode != http.StatusBadRequest || bad["code"] != "BAD_JSON" {
		t.Fatalf("/check bad json: status=%d body=%v", resp.StatusCode, bad)
	}
}
