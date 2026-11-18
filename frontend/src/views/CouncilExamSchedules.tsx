import { useEffect, useMemo, useState } from 'react'
import { type Call } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'
import { OfficialExamScheduleSheet } from './OfficialExamScheduleSheet'

type GroupSummary = {
  id: number; batch: string; track: string; student_count: number
  program: string; department: string; department_id: number | null
  counts: { total: number; approved: number; pending: number; cancelled: number; archived: number }
}
type ExamRow = {
  id: number; group_id: number; status: string
  exam_date: string; day: string; exam_type: string; start_time: string; end_time: string
  course_code: string; course_name: string; hall_name: string | null
}
type ExamSchedulesPayload = { academic_term: string; groups: GroupSummary[]; rows: ExamRow[] }

const STATUS_AR: Record<string, string> = { approved: 'معتمدة', pending: 'معلّقة', cancelled: 'ملغية', archived: 'مؤرشفة' }
const STATUS_COLOR: Record<string, string> = { approved: '#1f7a3d', pending: '#B26225', cancelled: '#B3261E', archived: '#6b6b6b' }

export default function CouncilExamSchedulesView({ call }: { call: Call }) {
  const [term, setTerm] = useState('2026/1')
  const [data, setData] = useState<ExamSchedulesPayload | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [showArchived, setShowArchived] = useState(false)
  const [sheetFor, setSheetFor] = useState<number | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [notice, setNotice] = useState<{ kind: 'ok' | 'warn'; text: string } | null>(null)

  useEffect(() => {
    setLoading(true); setErr(null)
    call<ExamSchedulesPayload>(`/api/council/exam-schedules?term=${encodeURIComponent(term)}`)
      .then(setData)
      .catch((e) => setErr((e as Error).message))
      .finally(() => setLoading(false))
  }, [term, reloadKey, call])

  const departments = useMemo(() => {
    const map = new Map<string, GroupSummary[]>()
    for (const g of data?.groups ?? []) {
      const key = g.department || 'بدون تخصص'
      const list = map.get(key) ?? []
      list.push(g)
      map.set(key, list)
    }
    return [...map.entries()]
  }, [data])

  const rowsOf = (groupId: number) => (data?.rows ?? []).filter((r) => r.group_id === groupId && (showArchived || r.status !== 'archived'))

  async function archive(group: GroupSummary) {
    if (!window.confirm(`أرشفة جدول امتحانات «${group.batch} — ${group.track}» بالكامل؟ ستخرج الامتحانات من كل المخارج (الدفعة · الورقة · فحص التعارض).`)) return
    setBusy(`archive-${group.id}`); setNotice(null)
    try {
      const r = await call<{ ok: boolean; archived?: number }>('/api/council/exam-schedules/archive', 'POST', { group_id: group.id, academic_term: term })
      setNotice({ kind: 'ok', text: `أُرشفت ${r.archived ?? 0} امتحان — الجدول خارج المسارات النشطة.` })
    } catch (e) { setNotice({ kind: 'warn', text: (e as Error).message }) } finally { setBusy(null); setReloadKey((k) => k + 1) }
  }

  async function unarchive(group: GroupSummary) {
    setBusy(`unarchive-${group.id}`); setNotice(null)
    try {
      const r = await call<{ ok: boolean; restored?: number }>('/api/council/exam-schedules/unarchive', 'POST', { group_id: group.id, academic_term: term })
      setNotice({ kind: 'ok', text: `أُعيدت ${r.restored ?? 0} فاينل إلى «معلّقة» — تحتاج اعتماد المدير من جديد لتُرى للجميع.` })
    } catch (e) { setNotice({ kind: 'warn', text: (e as Error).message }) } finally { setBusy(null); setReloadKey((k) => k + 1) }
  }

  async function deleteSchedule(group: GroupSummary) {
    const total = group.counts.total
    if (!window.confirm(`حذف جدول الامتحانات كاملًا: ${total} فاينل لشعبة «${group.batch} — ${group.track}» في ${term}.\nلا يمكن التراجع عن هذا الحذف. متابعة؟`)) return
    setBusy(`delete-${group.id}`); setNotice(null)
    try {
      const r = await call<{ ok: boolean; deleted?: number }>(`/api/council/exam-schedules/${group.id}?academic_term=${encodeURIComponent(term)}`, 'DELETE')
      setNotice({ kind: 'ok', text: `حُذف جدول الامتحانات كاملًا — ${r.deleted ?? 0} فاينل.` })
    } catch (e) { setNotice({ kind: 'warn', text: (e as Error).message }) } finally { setBusy(null); setReloadKey((k) => k + 1) }
  }

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
        <h1 className="h3 fw-bold mb-0 aust-h1">جدول الامتحانات</h1>
        <div className="d-flex gap-2 align-items-center">
          <label className="small fw-bold mb-0" htmlFor="term-input-exams">الفصل:</label>
          <input id="term-input-exams" className="form-control form-control-sm" style={{ width: 110 }} value={term} onChange={(e) => setTerm(e.target.value)} />
          <label className="d-flex align-items-center gap-1 small"><input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} /> عرض المؤرشفة</label>
        </div>
      </div>
      <p className="small text-muted mb-3">فاينل لكل مقرر في «الخطط الدراسية» — المصدر الوحيد هو «اقتراح الامتحانات التلقائي» ← «اعتماد الكل» ثم اعتماد المدير. من هنا العرض حسب التخصص والأرشفة والحذف الكامل — وألا توجد «ورقة رسمية» للامتحانات بعد.</p>

      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      {notice && <div className={`alert ${notice.kind === 'ok' ? 'alert-success' : 'alert-warning'}`} role="status">{notice.text}</div>}

      {loading && <div className="aust-panel p-3"><SkeletonRows rows={4} height={48} /></div>}

      {!loading && departments.map(([dept, groups]) => (
        <section key={dept} className="mb-4">
          <h2 className="h5 fw-bold mb-2" style={{ color: '#782B19' }}>تخصص: {dept}</h2>
          <div className="d-grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(340px,1fr))' }}>
            {groups.map((g) => (
              <article key={g.id} className="aust-panel p-3 d-flex flex-column gap-2">
                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <div>
                    <div className="fw-bold">دفعة {g.batch} — {g.track}</div>
                    <div className="small text-muted">{g.program} · {g.student_count} طالب</div>
                  </div>
                  <div className="d-flex gap-1 flex-wrap">
                    {(['approved', 'pending', 'cancelled', 'archived'] as const).map((s) => (
                      <span key={s} className="badge" style={{ background: STATUS_COLOR[s], color: '#fff' }} title={`${g.counts[s]} ${STATUS_AR[s]}`}>
                        {g.counts[s]} {STATUS_AR[s]}
                      </span>
                    ))}
                  </div>
                </div>

                <div className="d-flex gap-2 flex-wrap">
                  <button type="button" className="aust-btn-outline btn-sm" onClick={() => setSheetFor(g.id)}>الورقة الرسمية</button>
                  {g.counts.approved + g.counts.pending + g.counts.cancelled > 0 && (
                    <button type="button" className="aust-btn-outline btn-sm" disabled={busy === `archive-${g.id}`} onClick={() => archive(g)}>{busy === `archive-${g.id}` ? 'جارٍ الأرشفة…' : 'أرشفة الجدول'}</button>
                  )}
                  {g.counts.archived > 0 && (
                    <button type="button" className="aust-btn-outline btn-sm" disabled={busy === `unarchive-${g.id}`} onClick={() => unarchive(g)}>{busy === `unarchive-${g.id}` ? 'جارٍ الاستعادة…' : 'إلغاء الأرشفة'}</button>
                  )}
                  {g.counts.total > 0 && (
                    <button type="button" className="aust-btn-danger-outline btn-sm" disabled={busy === `delete-${g.id}`} onClick={() => deleteSchedule(g)}>{busy === `delete-${g.id}` ? 'جارٍ الحذف…' : 'حذف الجدول كاملًا'}</button>
                  )}
                </div>

                {rowsOf(g.id).length === 0 ? (
                  <div className="text-muted small">لا توجد امتحانات ظاهرة لهذه الشعبة في {term} — «اقتراح الامتحانات التلقائي» هو المدخل الوحيد لبناء الجدول.</div>
                ) : (
                  <div className="table-responsive" style={{ maxHeight: 420, overflowY: 'auto' }}>
                    <table className="table table-sm mb-0">
                      <thead className="table-light" style={{ position: 'sticky', top: 0 }}>
                        <tr><th>التاريخ</th><th>اليوم</th><th>الفترة</th><th>النوع</th><th>المقرر</th><th>القاعة</th><th>الحالة</th></tr>
                      </thead>
                      <tbody>
                        {rowsOf(g.id).map((r) => (
                          <tr key={r.id}>
                            <td dir="ltr">{r.exam_date}</td>
                            <td>{r.day}</td>
                            <td dir="ltr" className="small">{r.start_time.slice(0, 5)}–{r.end_time.slice(0, 5)}</td>
                            <td>{r.exam_type}</td>
                            <td><strong>{r.course_code}</strong> — {r.course_name}</td>
                            <td>{r.hall_name ?? '—'}</td>
                            <td><span className="badge" style={{ background: STATUS_COLOR[r.status] ?? '#6b6b6b', color: '#fff' }}>{STATUS_AR[r.status] ?? r.status}</span></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </article>
            ))}
          </div>
        </section>
      ))}

      {sheetFor !== null && <OfficialExamScheduleSheet call={call} groupId={sheetFor} onClose={() => setSheetFor(null)} />}
    </>
  )
}
