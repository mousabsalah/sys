import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type Semester = {
  id: number
  faculty_id: number
  department_id: number
  batch_id: number
  academic_term: string
  start_date: string | null
  end_date: string | null
  archived_at: string | null
  faculty?: string
  department?: string
  batch?: string
}

type ArchivedResp = {
  semester: {
    id: number
    academic_term: string
    start_date: string | null
    end_date: string | null
    archived_at: string | null
    faculty?: string
    department?: string
    batch?: string
    student_count?: number
  }
  timetables: { id: number; group_id: number; group_track: string; course_code: string | null; course_name: string | null; day_of_week: string; start_time: string; end_time: string; hall_name: string | null; faculty_name: string | null }[]
  exams: { id: number; course_code: string | null; course_name: string | null; exam_date: string; start_time: string; end_time: string; hall_name: string | null; group_id: number; group_track: string }[]
}

export default function AdminArchiveView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [semesters, setSemesters] = useState<Semester[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ArchivedResp | null>(null)
  const [loading, setLoading] = useState(false)
  const [listLoading, setListLoading] = useState(true)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    call<{ semesters: Semester[] }>('/api/admin/semesters')
      .then((r) => setSemesters((r.semesters ?? []).filter((s) => s.archived_at)))
      .catch((e) => setErr((e as Error).message))
      .finally(() => setListLoading(false))
  }, [call])

  async function open(id: number) {
    setSelectedId(id); setDetail(null); setLoading(true); setErr(null)
    try {
      setDetail(await call<ArchivedResp>(`/api/admin/semesters/${id}/archived`))
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>أرشيف الفصول السابقة</h1>
      <p className="small text-muted mb-3">جداول وامتحانات الفصول المؤرشفة — عرض للقراءة فقط، مفهرسة حسب الكلية ← القسم ← السنة ← الدفعة.</p>
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="row g-3">
        <div className="col-md-4">
          <div className="aust-panel p-3">
            <h2 className="h6 fw-bold mb-2" style={{ color: '#782B19' }}>الفصول المؤرشفة ({semesters.length})</h2>
            <div className="list-group">
              {listLoading && <div className="text-muted small p-2">جارٍ التحميل…</div>}
              {semesters.map((s) => (
                <button
                  key={s.id}
                  className={`list-group-item list-group-item-action text-end border rounded mb-1 ${selectedId === s.id ? 'active' : ''}`}
                  style={selectedId === s.id ? { background: '#0A1D3B', borderColor: '#0A1D3B', color: '#fff' } : undefined}
                  onClick={() => open(s.id)}
                >
                  <div className="d-flex justify-content-between align-items-center">
                    <strong dir="ltr">{s.academic_term}</strong>
                    <span className="badge" style={{ background: '#782B19', color: '#fff' }}>{s.department ?? `#${s.department_id}`}</span>
                  </div>
                  <div className="small" style={selectedId === s.id ? { color: '#d6c9b4' } : { color: '#666' }}>
                    {s.faculty ?? `#${s.faculty_id}`} · {s.batch ?? `#${s.batch_id}`}
                    {s.start_date ? <span dir="ltr"> · {s.start_date} ← {s.end_date}</span> : null}
                  </div>
                </button>
              ))}
              {!listLoading && semesters.length === 0 && <div className="text-muted small p-2">لا توجد فصول مؤرشفة بعد — تُؤرشف من صفحة «الفصول الدراسية».</div>}
            </div>
          </div>
        </div>

        <div className="col-md-8">
          {loading && <div className="aust-panel p-4 text-center text-muted">جارٍ تحميل الأرشيف…</div>}
          {!loading && !detail && <div className="aust-panel p-4 text-center text-muted">اختر فصلًا من القائمة لعرض جداوله</div>}
          {detail && (
            <div className="aust-panel p-3">
              <div className="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                <div>
                  <h2 className="h5 mb-0" style={{ color: '#0A1D3B' }}>فصل {detail.semester.academic_term} — {detail.semester.department} / {detail.semester.batch}</h2>
                  <div className="small text-muted">{detail.semester.faculty} · {detail.semester.student_count ?? '—'} طالب · مؤرشف في {detail.semester.archived_at ? new Date(detail.semester.archived_at).toLocaleDateString('ar-SD') : '—'}</div>
                </div>
                <span className="badge" style={{ background: '#555', color: '#fff' }}>للقراءة فقط</span>
              </div>

              <h3 className="h6 fw-bold" style={{ color: '#782B19' }}>المحاضرات ({detail.timetables.length})</h3>
              <div className="table-responsive mb-3" style={{ maxHeight: 320, overflowY: 'auto' }}>
                <table className="table table-sm table-striped align-middle">
                  <thead className="table-light"><tr><th>اليوم</th><th>من</th><th>إلى</th><th>المقرر</th><th>القاعة</th><th>الشعبة</th><th>الأستاذ</th></tr></thead>
                  <tbody>
                    {detail.timetables.map((t) => (
                      <tr key={t.id}>
                        <td>{t.day_of_week}</td>
                        <td dir="ltr">{t.start_time}</td>
                        <td dir="ltr">{t.end_time}</td>
                        <td>{t.course_code} — {t.course_name ?? ''}</td>
                        <td>{t.hall_name ?? '—'}</td>
                        <td>{t.group_track} #{t.group_id}</td>
                        <td className="small">{t.faculty_name ?? '—'}</td>
                      </tr>
                    ))}
                    {detail.timetables.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد محاضرات في هذا الفصل</td></tr>}
                  </tbody>
                </table>
              </div>

              <h3 className="h6 fw-bold" style={{ color: '#782B19' }}>الامتحانات ({detail.exams.length})</h3>
              <div className="table-responsive" style={{ maxHeight: 220, overflowY: 'auto' }}>
                <table className="table table-sm table-striped align-middle">
                  <thead className="table-light"><tr><th>التاريخ</th><th>من</th><th>إلى</th><th>المقرر</th><th>القاعة</th><th>الشعبة</th></tr></thead>
                  <tbody>
                    {detail.exams.map((e) => (
                      <tr key={e.id}>
                        <td dir="ltr">{e.exam_date}</td>
                        <td dir="ltr">{e.start_time}</td>
                        <td dir="ltr">{e.end_time}</td>
                        <td>{e.course_code} — {e.course_name ?? ''}</td>
                        <td>{e.hall_name ?? '—'}</td>
                        <td>{e.group_track} #{e.group_id}</td>
                      </tr>
                    ))}
                    {detail.exams.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد امتحانات في هذا الفصل</td></tr>}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  )
}
