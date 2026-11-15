import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type State = 'active' | 'upcoming' | 'ended' | 'archived'

type SemesterRow = {
  id: number
  faculty_id: number
  department_id: number
  batch_id: number
  academic_term: string
  start_date: string | null
  end_date: string | null
  archived_at: string | null
  faculty?: string | null
  department?: string | null
  batch?: string | null
  student_count?: number | null
  state: State
}

type BatchOption = { id: number; name: string; student_count: number }

const emptyYear = {
  yearLabel: '',
  term1: { start_date: '', end_date: '' },
  term2: { start_date: '', end_date: '' },
}

const stateBadge = (s: State): { text: string; bg: string } => (s === 'active' ? { text: 'نشط الآن', bg: '#1f7a3d' }
  : s === 'upcoming' ? { text: 'قادم', bg: '#B26225' }
  : s === 'ended' ? { text: 'انتهى', bg: '#6c757d' }
  : { text: 'مؤرشف', bg: '#782B19' })

export default function CouncilAcademicYearView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [rows, setRows] = useState<SemesterRow[]>([])
  const [activeId, setActiveId] = useState<number | null>(null)
  const [batches, setBatches] = useState<BatchOption[]>([])
  const [year, setYear] = useState(emptyYear)
  const [yearLabelDraft, setYearLabelDraft] = useState('')
  const [scope, setScope] = useState<'all' | 'pick'>('all')
  const [pickedIds, setPickedIds] = useState<number[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [editing, setEditing] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({ academic_term: '', start_date: '', end_date: '' })
  const [showOpen, setShowOpen] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const [sem, batchData] = await Promise.all([
        call<{ semesters: SemesterRow[]; active_id: number | null; total: number }>('/api/council/semesters'),
        call<{ batches: { id: number; name: string; student_count: number }[] }>('/api/council/batch-accounts'),
      ])
      setRows(sem.semesters ?? [])
      setActiveId(sem.active_id ?? null)
      setBatches(batchData.batches ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [reloadKey, call])

  const activeSemester = rows.find((r) => r.id === activeId)

  async function openYear() {
    if (!year.term1.start_date || !year.term1.end_date || !year.term2.start_date || !year.term2.end_date) {
      setErr('حدّد تاريخ بداية ونهاية السمسترين')
      return
    }
    if (year.term1.end_date < year.term1.start_date || year.term2.end_date < year.term2.start_date) {
      setErr('تاريخ النهاية قبل تاريخ البداية في أحد السمسترين')
      return
    }
    const launchTerm1 = yearLabelDraft.trim() ? `${yearLabelDraft.trim()}/1` : ''
    const launchTerm2 = yearLabelDraft.trim() ? `${yearLabelDraft.trim()}/2` : ''
    if (!launchTerm1 || !launchTerm2) { setErr('اكتب اسم السنة (مثل 2026/2027)'); return }

    setBusy(true); setErr(null); setMsg(null)
    try {
      const payload: Record<string, unknown> = {
        semesters: [
          { term: launchTerm1, start_date: year.term1.start_date, end_date: year.term1.end_date },
          { term: launchTerm2, start_date: year.term2.start_date, end_date: year.term2.end_date },
        ],
      }
      if (scope === 'pick' && pickedIds.length > 0) payload.batch_ids = pickedIds
      const r = await call<{ ok: boolean; created_count: number }>('/api/council/semesters/open-year', 'POST', payload)
      setMsg(`تم فتح السنة الدراسية — ${r.created_count} فصلًا أُنشئت ✓`)
      setShowOpen(false)
      setYear(emptyYear)
      setYearLabelDraft('')
      setScope('all'); setPickedIds([])
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  async function saveEdit() {
    if (editing === null) return
    setBusy(true); setErr(null); setMsg(null)
    try {
      await call(`/api/council/semesters/${editing}`, 'PUT', {
        academic_term: editForm.academic_term,
        start_date: editForm.start_date || null,
        end_date: editForm.end_date || null,
      })
      setMsg('تم تحديث الفصل ✓')
      setEditing(null)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  async function archive(s: SemesterRow) {
    const backup = true // أرشفة تقوم معًا مع نسخة احتياطية تلقائية
    if (!window.confirm(`أرشفة فصل «${s.academic_term}» (${s.batch} / ${s.department ?? ''})؟\nستُجمّد محاضراته وامتحاناته ولن تحجب الجدولة، وستُنشأ نسخة احتياطية${backup ? ' تلقائيًا' : ''}.`)) return
    setBusy(true); setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; backup: string | null }>(`/api/council/semesters/${s.id}/archive`, 'POST')
      setMsg(`تمت أرشفة الفصل ✓ — نسخة احتياطية: ${r.backup ?? 'تعذر إنشاؤها (مُسجّلة في التدقيق)'}`)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  async function unarchive(s: SemesterRow) {
    if (!window.confirm(`إلغاء أرشفة فصل «${s.academic_term}»؟ ستعود محاضراته لتحجب الجدولة.`)) return
    setBusy(true); setErr(null); setMsg(null)
    try {
      await call(`/api/council/semesters/${s.id}/unarchive`, 'POST')
      setMsg('تم إلغاء الأرشفة')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  async function remove(s: SemesterRow) {
    if (!window.confirm(`حذف فصل «${s.academic_term}»؟ ممنوع إذا كان مرتبطًا بجداول أو امتحانات.`)) return
    setBusy(true); setErr(null); setMsg(null)
    try {
      await call(`/api/council/semesters/${s.id}`, 'DELETE')
      setMsg('تم حذف الفصل')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  if (err && rows.length === 0) return <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />
  if (loading && rows.length === 0) return <SkeletonRows rows={6} height={44} />

  return (
    <>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <h1 className="h3 fw-bold mb-0" style={{ color: '#0A1D3B' }}>بداية السنة الدراسية</h1>
        <button className="aust-btn" onClick={() => setShowOpen((s) => !s)}>{showOpen ? 'إخفاء النموذج' : '+ فتح السنة الدراسية'}</button>
      </div>

      {activeSemester
        ? <div className="alert alert-success py-2 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>الفصل النشط الآن: <strong>{activeSemester.academic_term}</strong> — {activeSemester.batch} · {activeSemester.department ?? ''} · من {activeSemester.start_date ?? '—'} إلى {activeSemester.end_date ?? '—'}</span>
            <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>نشط</span>
          </div>
        : <div className="alert alert-warning py-2 mb-3">لا يوجد فصل نشط الآن — افتح السنة الدراسية أو حدّد التواريخ للفصل القادم.</div>}

      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}

      {/* ── فتح السنة ── */}
      {showOpen && (
        <div className="aust-panel p-3 mb-3">
          <h2 className="h6 fw-bold mb-2" style={{ color: '#0A1D3B' }}>فتح السنة الدراسية (سمسترين متزامنين)</h2>
          <div className="d-flex gap-2 flex-wrap align-items-end mb-3">
            <div>
              <label className="form-label" htmlFor="ay">السنة الدراسية</label>
              <input id="ay" className="form-control" style={{ maxWidth: 170 }} placeholder="مثال 2026/2027" value={yearLabelDraft} onChange={(e) => setYearLabelDraft(e.target.value)} />
            </div>
            <div>
              <label className="form-label d-block mb-1">الفصل الأول — {yearLabelDraft.trim() ? `${yearLabelDraft.trim()}/1` : '2026/1'}</label>
              <div className="d-flex gap-2">
                <input type="date" className="form-control" dir="ltr" aria-label="بداية الفصل الأول" value={year.term1.start_date} onChange={(e) => setYear({ ...year, term1: { ...year.term1, start_date: e.target.value } })} />
                <input type="date" className="form-control" dir="ltr" aria-label="نهاية الفصل الأول" value={year.term1.end_date} onChange={(e) => setYear({ ...year, term1: { ...year.term1, end_date: e.target.value } })} />
              </div>
            </div>
            <div>
              <label className="form-label d-block mb-1">الفصل الثاني — {yearLabelDraft.trim() ? `${yearLabelDraft.trim()}/2` : '2026/2'}</label>
              <div className="d-flex gap-2">
                <input type="date" className="form-control" dir="ltr" aria-label="بداية الفصل الثاني" value={year.term2.start_date} onChange={(e) => setYear({ ...year, term2: { ...year.term2, start_date: e.target.value } })} />
                <input type="date" className="form-control" dir="ltr" aria-label="نهاية الفصل الثاني" value={year.term2.end_date} onChange={(e) => setYear({ ...year, term2: { ...year.term2, end_date: e.target.value } })} />
              </div>
            </div>
          </div>

          <div className="d-flex gap-2 flex-wrap align-items-center mb-2">
            <span className="small fw-bold">تطبيق على:</span>
            <label className="form-check-label d-flex align-items-center gap-1"><input type="radio" name="scope" className="form-check-input m-0" checked={scope === 'all'} onChange={() => setScope('all')} /> كل الدفعات ({batches.length})</label>
            <label className="form-check-label d-flex align-items-center gap-1"><input type="radio" name="scope" className="form-check-input m-0" checked={scope === 'pick'} onChange={() => setScope('pick')} /> دفعات محددة</label>
            {scope === 'pick' && (
              <select multiple className="form-select" style={{ maxWidth: 320, height: 90 }} aria-label="اختر الدفعات" value={pickedIds.map(String)} onChange={(e) => setPickedIds(Array.from(e.target.selectedOptions).map((o) => Number(o.value)))}>
                {batches.map((b) => <option key={b.id} value={b.id}>{b.name} ({b.student_count} طالب)</option>)}
              </select>
            )}
            <button className="aust-btn" disabled={busy} onClick={openYear}>{busy ? 'جارٍ الإنشاء…' : 'إنشاء السمسترين'}</button>
          </div>
          <p className="small text-muted mb-0">يتحقق النظام أولًا من عدم وجود أي فصل بنفس الصيغة — إن وُجد، تُرفض العملية كاملة وتُعرض الفصول المتعارضة.</p>
        </div>
      )}

      {/* ── الفصول الدراسية ── */}
      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>الفصل</th><th>الدفعة / القسم</th><th>من</th><th>إلى</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
            {rows.map((s) => (
              <tr key={s.id} style={s.archived_at ? { opacity: 0.6 } : undefined}>
                <td dir="ltr" className="fw-bold">{s.academic_term}</td>
                <td>{s.batch}<div className="small text-muted">{s.department ?? ''} · {s.student_count ?? ''}</div></td>
                <td dir="ltr" className="small">{s.start_date ?? '—'}</td>
                <td dir="ltr" className="small">{s.end_date ?? '—'}</td>
                <td><span className="badge" style={{ background: stateBadge(s.state).bg, color: '#fff' }}>{stateBadge(s.state).text}</span></td>
                <td className="d-flex gap-1 flex-wrap">
                  {editing === s.id ? (
                    <span className="d-flex gap-1 align-items-center flex-wrap">
                      <input className="form-control form-control-sm" style={{ maxWidth: 110 }} dir="ltr" placeholder="2026/1" value={editForm.academic_term} onChange={(e) => setEditForm({ ...editForm, academic_term: e.target.value })} />
                      <input type="date" className="form-control form-control-sm" dir="ltr" value={editForm.start_date} onChange={(e) => setEditForm({ ...editForm, start_date: e.target.value })} />
                      <input type="date" className="form-control form-control-sm" dir="ltr" value={editForm.end_date} onChange={(e) => setEditForm({ ...editForm, end_date: e.target.value })} />
                      <button className="aust-btn btn-sm" disabled={busy} onClick={saveEdit}>{busy ? '…' : 'حفظ'}</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => setEditing(null)}>إلغاء</button>
                    </span>
                  ) : (
                    <>
                      {!s.archived_at && (
                        <>
                          <button className="aust-btn-outline btn-sm" onClick={() => { setEditing(s.id); setEditForm({ academic_term: s.academic_term, start_date: s.start_date ?? '', end_date: s.end_date ?? '' }) }}>تعديل</button>
                          <button className="aust-btn-outline btn-sm" onClick={() => archive(s)}>أرشفة</button>
                        </>
                      )}
                      {s.archived_at && <button className="aust-btn-outline btn-sm" onClick={() => unarchive(s)}>فك الأرشفة</button>}
                      <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(s)}>حذف</button>
                    </>
                  )}
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد فصول — افتح السنة الدراسية أولًا من الزر أعلاه</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
