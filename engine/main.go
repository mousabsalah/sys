package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"time"
)

// ── syst-engine: سروال Go صغير لمنطق التعارض
// يستقبل الحمولة من Laravel (كل بيانات القاعة/الشعبة/المدرس/المدى/الموجود)
// ويرجع تقرير الأسباب (severity: hard|soft) — Stateless تمامًا.
const (
	maxBodyBytes      = 4 << 20 // 4 MiB — سقف أمان لجسم الطلب
	solverExecTimeout = 30 * time.Second
	healthVersion     = "0.1.0"
)

type Violation struct {
	Code          string `json:"code"`
	MessageArabic string `json:"message_ar"`
	Severity      string `json:"severity"` // hard|soft
}

type CheckResult struct {
	Ok         bool        `json:"ok"`
	Conflict   bool        `json:"conflict"`
	Type       string      `json:"type"`
	Violations []Violation `json:"violations"`
}

type ExistingSlot struct {
	ID        int    `json:"id"`
	Kind      string `json:"kind"` // lecture|exam|booking
	DayOfWeek string `json:"day_of_week"`
	ExamDate  string `json:"exam_date"`
	StartTime string `json:"start_time"`
	EndTime   string `json:"end_time"`
	HallID    int    `json:"hall_id"`
	FacultyID int    `json:"faculty_id"`
	GroupID   int    `json:"group_id"`
	CourseID  int    `json:"course_id"`
}

type Payload struct {
	Type              string         `json:"type"` // lecture|exam|booking
	DayOfWeek         string         `json:"day_of_week"`
	ExamDate          string         `json:"exam_date"`
	StartTime         string         `json:"start_time"`
	EndTime           string         `json:"end_time"`
	HallID            int            `json:"hall_id"`
	FacultyID         int            `json:"faculty_id"`
	GroupID           int            `json:"group_id"`
	CourseID          int            `json:"course_id"`
	HallStatus        string         `json:"hall_status"`
	HallCapacity      *int           `json:"hall_capacity"`
	GroupStudentCount *int           `json:"group_student_count"`
	Eligible          *bool          `json:"eligible"`
	Existing          []ExistingSlot `json:"existing"`
}

func overlaps(aStart, aEnd, bStart, bEnd string) bool {
	return aStart < bEnd && bStart < aEnd
}

func samePeriod(a Payload, b ExistingSlot) bool {
	// للتواريخ الفعلية (الامتحانات/الحجوزات) نقارن ExamDate، وإلا اليوم الأسبوعي.
	if a.ExamDate != "" && b.ExamDate != "" {
		return a.ExamDate == b.ExamDate && overlaps(a.StartTime, a.EndTime, b.StartTime, b.EndTime)
	}
	dayA := a.DayOfWeek
	if dayA == "" && a.ExamDate != "" {
		dayA = resolveDayOfWeekFromDateString(a.ExamDate)
	}
	dayB := b.DayOfWeek
	if dayB == "" && b.ExamDate != "" {
		dayB = resolveDayOfWeekFromDateString(b.ExamDate)
	}
	return dayA != "" && dayA == dayB && overlaps(a.StartTime, a.EndTime, b.StartTime, b.EndTime)
}

func resolveDayOfWeekFromDateString(s string) string {
	if len(s) < 10 {
		return ""
	}
	// 2026-01-09 (yyyy-mm-dd) — نفكك القيم ونستعمل time.Parse
	// قائمة أيام الأسبوع العربية بالترتيب من الاثنين
	weekdays := map[time.Weekday]string{
		time.Saturday:  "السبت",
		time.Sunday:    "الأحد",
		time.Monday:    "الاثنين",
		time.Tuesday:   "الثلاثاء",
		time.Wednesday: "الأربعاء",
		time.Thursday:  "الخميس",
		time.Friday:    "الجمعة",
	}
	t, err := time.Parse("2006-01-02", s[:10])
	if err != nil {
		return ""
	}
	return weekdays[t.Weekday()]
}

func check(p Payload) CheckResult {
	violations := []Violation{}
	add := func(code, msg string) {
		violations = append(violations, Violation{Code: code, MessageArabic: msg, Severity: "hard"})
	}

	// تحقق أساسي بالنوع
	if p.Type == "exam" && p.ExamDate == "" {
		add("MISSING_EXAM_DATE", "تاريخ الامتحان مطلوب للنوع exam")
	}
	if p.Type != "exam" && p.DayOfWeek == "" && p.ExamDate == "" {
		add("MISSING_DAY_OF_WEEK", "اليوم الأسبوعي أو التاريخ مطلوب للنوع lecture|booking")
	}
	if p.StartTime != "" && p.EndTime != "" && p.EndTime <= p.StartTime {
		add("INVALID_TIME_RANGE", "وقت النهاية يجب أن يكون بعد وقت البداية")
	}

	// حالة القاعة (متاحة/صيانة/مغلقة)
	if p.HallStatus != "" && p.HallStatus != "متاحة" {
		add("HALL_UNAVAILABLE", fmt.Sprintf("القاعة غير متاحة (الحالة: %s)", p.HallStatus))
	}

	// أهلية المدرّس للمادة (R13) — إن مررها الـ Laravel
	if p.Eligible != nil && !*p.Eligible {
		add("FACULTY_NOT_ELIGIBLE", "المدرّس غير مُسند لهذا المقرر (R13)")
	}

	// سعة القاعة ضد عدد الطلاب للشعبة
	if p.HallCapacity != nil && p.GroupStudentCount != nil && *p.GroupStudentCount > *p.HallCapacity {
		add("HALL_CAPACITY", fmt.Sprintf("السعة غير كافية: القاعة %d < الطلاب %d", *p.HallCapacity, *p.GroupStudentCount))
	}

	// تعارضات مع الموجود (محاضرة/امتحان/حجز)
	for _, e := range p.Existing {
		// COURSE_DUPLICATE: امتحان نفس المقرر لنفس الشعبة في نفس التاريخ — دون أن تتطلب فترة زمنية
		if p.Type == "exam" && e.Kind == "exam" && e.CourseID == p.CourseID && e.CourseID > 0 && e.GroupID == p.GroupID && e.GroupID > 0 && e.ExamDate != "" && p.ExamDate != "" && e.ExamDate == p.ExamDate {
			add("COURSE_EXAM_DUPLICATE", "لا يمكن امتحانان لنفس المقرر في نفس الشعبة في نفس اليوم")
		}
		if !samePeriod(p, e) {
			continue
		}
		// قاعة مشغولة في نفس الفترة (بأي نوع)
		if e.HallID == p.HallID && e.HallID > 0 {
			switch e.Kind {
			case "lecture":
				add("HALL_BUSY", "القاعة محجوزة لمحاضرة في هذا الوقت")
			case "exam":
				add("HALL_BUSY_EXAM", "القاعة محجوزة لامتحان في هذا الوقت")
			case "booking":
				add("HALL_BUSY_BOOKING", "القاعة محجوزة لنشاط في هذا الوقت")
			}
		}
		// نفس المدرس في شعبتين متزامنتين
		if e.FacultyID == p.FacultyID && e.FacultyID > 0 && e.Kind == "lecture" {
			add("FACULTY_OVERLAP", "عضو هيئة التدريس في محاضرتين في هذا الوقت")
		}
		// نفس الشعبة
		if e.GroupID == p.GroupID && e.GroupID > 0 {
			switch e.Kind {
			case "lecture":
				add("GROUP_OVERLAP", "الشعبة في محاضرة أخرى في هذا الوقت")
			case "exam":
				add("GROUP_EXAM_CONFLICT", "الشعبة لديها امتحان في نفس الوقت")
			}
		}
	}

	conflict := false
	for _, v := range violations {
		if v.Severity == "hard" {
			conflict = true
			break
		}
	}
	return CheckResult{Ok: !conflict, Conflict: conflict, Type: p.Type, Violations: violations}
}

// ── /propose: اقتراح جدول تلقائي عبر Google OR-Tools CP-SAT ─────────
// Go يستدعي solver.py (Python) كعملية فرعية؛ فشل Python لا يُسقط النظام،
// وإنما يُعيد 503 مع رسالة عربية واضحة.
func handlePropose(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "POST only")
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, maxBodyBytes))
	if err != nil {
		log.Printf("propose: read body failed: %v", err)
		writeError(w, http.StatusBadRequest, "BAD_BODY", "invalid body")
		return
	}
	var probe struct {
		Group struct {
			ID int `json:"id"`
		} `json:"group"`
	}
	if err := json.Unmarshal(body, &probe); err != nil {
		log.Printf("propose: unmarshal payload failed: %v", err)
		writeError(w, http.StatusBadRequest, "BAD_JSON", "invalid JSON payload")
		return
	}
	if probe.Group.ID <= 0 {
		writeError(w, http.StatusBadRequest, "MISSING_GROUP_ID", "missing group.id")
		return
	}

	solverPath := os.Getenv("SYST_SOLVER_PATH")
	if solverPath == "" {
		solverPath = "./solver.py"
	}
	pythonBin := os.Getenv("SYST_PYTHON")
	if pythonBin == "" {
		pythonBin = "python"
	}

	ctx, cancel := context.WithTimeout(r.Context(), solverExecTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, pythonBin, solverPath)
	var stdout, stderr bytes.Buffer
	cmd.Stdin = bytes.NewReader(body)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	cmd.Env = append(os.Environ(), "PYTHONIOENCODING=utf-8", "PYTHONUTF8=1")
	err = cmd.Run()
	output := stdout.Bytes()
	if err != nil {
		// المخرج يصلح للتشخيص — 503 العربي يبقى للمستخدم لكن الحقيقية تسجل في stdout
		log.Printf("propose: solver exec failed: %v — stderr: %s", err, tailString(stderr.String(), 1024))
		writeError(w, http.StatusServiceUnavailable, "SOLVER_UNREACHABLE", "تعذر تشغيل محرك الاقتراح (CP-SAT). حاول لاحقًا أو تابع الجدولة يدويًا.")
		return
	}
	if stderr.Len() > 0 {
		log.Printf("propose: solver stderr: %s", tailString(stderr.String(), 1024))
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal(output, &parsed); err != nil {
		log.Printf("propose: solver output is not valid JSON: %v", err)
		writeError(w, http.StatusServiceUnavailable, "SOLVER_BAD_RESPONSE", "استجابة غير صالحة من المحرك")
		return
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(output)
}

// ── /propose-exams: اقتراح جدول امتحانات فاينل — Google OR-Tools CP-SAT ───
// نفس مفهوم /propose لكن بفترات امتحانية على تواريخ حقيقية (مدى يدوي من الشاشة).
func handleProposeExams(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "POST only")
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, maxBodyBytes))
	if err != nil {
		log.Printf("propose-exams: read body failed: %v", err)
		writeError(w, http.StatusBadRequest, "BAD_BODY", "invalid body")
		return
	}
	var probe struct {
		Group struct {
			ID int `json:"id"`
		} `json:"group"`
		ExamSlots []json.RawMessage `json:"exam_slots"`
		Courses   []json.RawMessage `json:"courses"`
	}
	if err := json.Unmarshal(body, &probe); err != nil {
		log.Printf("propose-exams: unmarshal payload failed: %v", err)
		writeError(w, http.StatusBadRequest, "BAD_JSON", "invalid JSON payload")
		return
	}
	if probe.Group.ID <= 0 {
		writeError(w, http.StatusBadRequest, "MISSING_GROUP_ID", "missing group.id")
		return
	}
	if len(probe.ExamSlots) == 0 {
		writeError(w, http.StatusBadRequest, "MISSING_EXAM_SLOTS", "exam_slots مطلوب وغير فارغ — حدد مدى تواريخ الامتحانات")
		return
	}

	solverPath := os.Getenv("SYST_SOLVER_EXAM_PATH")
	if solverPath == "" {
		solverPath = "./solver_exams.py"
	}
	pythonBin := os.Getenv("SYST_PYTHON")
	if pythonBin == "" {
		pythonBin = "python"
	}

	ctx, cancel := context.WithTimeout(r.Context(), solverExecTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, pythonBin, solverPath)
	var stdout, stderr bytes.Buffer
	cmd.Stdin = bytes.NewReader(body)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	cmd.Env = append(os.Environ(), "PYTHONIOENCODING=utf-8", "PYTHONUTF8=1")
	err = cmd.Run()
	output := stdout.Bytes()
	if err != nil {
		log.Printf("propose-exams: solver exec failed: %v — stderr: %s", err, tailString(stderr.String(), 1024))
		writeError(w, http.StatusServiceUnavailable, "SOLVER_UNREACHABLE", "تعذر تشغيل محرك الاقتراح (CP-SAT). حاول لاحقًا أو تابع الجدولة يدويًا.")
		return
	}
	if stderr.Len() > 0 {
		log.Printf("propose-exams: solver stderr: %s", tailString(stderr.String(), 1024))
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal(output, &parsed); err != nil {
		log.Printf("propose-exams: solver output is not valid JSON: %v", err)
		writeError(w, http.StatusServiceUnavailable, "SOLVER_BAD_RESPONSE", "استجابة غير صالحة من المحرك")
		return
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(output)
}

// tailString يرجع آخر n بايت من s — يمنع flood في الـ logs لو solver طلع traceback طويل.
func tailString(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return "…" + s[len(s)-n:]
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}

// writeError موحّد — مكان واحد لكل ردود الخطأ (code + message).
func writeError(w http.ResponseWriter, status int, code, msg string) {
	writeJSON(w, status, map[string]string{"code": code, "error": msg})
}

func corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func newMux() *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"service": "syst-engine",
			"status":  "ok",
			"version": healthVersion,
		})
	})

	mux.HandleFunc("/check", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "POST only")
			return
		}
		body, err := io.ReadAll(io.LimitReader(r.Body, maxBodyBytes))
		if err != nil {
			log.Printf("check: read body failed: %v", err)
			writeError(w, http.StatusBadRequest, "BAD_BODY", "invalid body")
			return
		}
		var p Payload
		if err := json.Unmarshal(body, &p); err != nil {
			log.Printf("check: unmarshal payload failed: %v", err)
			writeError(w, http.StatusBadRequest, "BAD_JSON", "invalid JSON payload")
			return
		}
		if p.Type == "" {
			writeError(w, http.StatusBadRequest, "MISSING_TYPE", "missing type")
			return
		}
		writeJSON(w, http.StatusOK, check(p))
	})

	mux.HandleFunc("/propose", handlePropose)
	mux.HandleFunc("/propose-exams", handleProposeExams)

	return mux
}

func main() {
	portNum, err := strconv.Atoi(os.Getenv("SYST_ENGINE_PORT"))
	if err != nil || portNum < 1 || portNum > 65535 {
		portNum = 8080
	}
	log.Printf("syst-engine listening on :%d", portNum)

	srv := &http.Server{
		Addr:              fmt.Sprintf(":%d", portNum),
		Handler:           corsMiddleware(newMux()),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      90 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	log.Fatal(srv.ListenAndServe())
}
