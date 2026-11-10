import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type ProposalAssignment = {
  course_id: number; code: string; name: string
  period_id: number; day: string; start_time: string; end_time: string; label: string
  hall_id: number; hall_name: string; faculty_id: number; faculty_name: string
  academic_term: string; group_id: number
}
type ProposeUnplaced = { course_id: number; code: string; name: string; sessions: number; reason: string; group_id?: number }
type ProposeGroupSummary = { group_id: number; ok: boolean; status: string; placed: number; batch: string; track: string }
type ProposeResult = {
  ok: boolean; message_ar?: string
  status?: string; placed?: number; multi?: boolean
  groups?: ProposeGroupSummary[]
  assignments?: ProposalAssignment[]; unplaced?: ProposeUnplaced[]
}
type PlanItem = { course_id: number; code: string; name: string; sessions_per_week: number }
type ProposeContext = {
  groups: { id: number; batch: string; track: string; student_count: number; program_id: number }[]
  courses: { id: number; code: string; name: string; credit_hours: number }[]
  plans: Record<number, PlanItem[]>
}

export default function ProposeView({ call, onAdopted }: { call: ReturnType<typeof useAuthedApi>; onAdopted: () => void }) {
  const [ctx, setCtx] = useState<ProposeContext | null>(null)
  const [ctxErr, setCtxErr] = useState<string | null>(null)
  const [mode, setMode] = useState<'single' | 'all'>('single')
  const [groupId, setGroupId] = useState<number | null>(null)
  const [courseIds, setCourseIds] = useState<number[]>([])
  const [term, setTerm] = useState('2026/1')
  const [result, setResult] = useState<ProposeResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [adopting, setAdopting] = useState(false)
  const [adoptState, setAdoptState] = useState<'idle' | 'ok' | 'conflict' | 'fail'>('idle')
  const [adoptMsg, setAdoptMsg] = useState<string | null>(null)

  useEffect(() => {
    call<ProposeContext>('/api/admin/propose-timetable/context')
      .then((d) => { setCtx(d); setGroupId(d.groups[0]?.id ?? null) })
      .catch((e) => setCtxErr((e as Error).message))
  }, [call])

  // عند تغيير الشعبة (أو اكتمال التحميل) ← حمولة الخطة الافتراضية لهذه الشعبة
  useEffect(() => {
    if (!ctx || groupId === null) return
    const plan = ctx.plans?.[groupId] ?? []
    setCourseIds(plan.map((p) => p.course_id))
  }, [ctx, groupId])

  function sessionsFor(courseId: number): number {
    const plan = groupId !== null ? ctx?.plans?.[groupId] : undefined
    const planned = plan?.find((p) => p.course_id === courseId)?.sessions_per_week
    if (planned) return Math.max(1, Math.min(6, planned))
    const credit = ctx?.courses.find((c) => c.id === courseId)?.credit_hours ?? 3
    return Math.max(1, Math.min(6, credit))
  }

  function toggleCourse(id: number) {
    setCourseIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  async function propose(e: React.FormEvent) {
    e.preventDefault()
    if (mode === 'single' && (!groupId || courseIds.length === 0)) return
    setBusy(true); setResult(null); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const body = mode === 'single'
        ? { group_id: groupId, course_ids: courseIds, academic_term: term }
        : { group_ids: ctx?.groups.map((g) => g.id), academic_term: term }
      const r = await call<ProposeResult>('/api/admin/propose-timetable', 'POST', body)
      setResult(r)
    } catch (er) { setResult({ ok: false, message_ar: (er as Error).message }) }
    finally { setBusy(false) }
  }

  async function adoptAll() {
    if (!result?.assignments?.length) return
    setAdopting(true); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const r = await call<{ ok: boolean; created?: number; message_ar?: string; conflicts?: { index: number; course_code?: string; day: string; start_time: string; violations?: { message_ar: string }[] }[] }>(
        '/api/timetables/bulk', 'POST', {
          assignments: result.assignments.map((a) => ({
            course_id: a.course_id, course_code: a.code, faculty_id: a.faculty_id, group_id: a.group_id,
            hall_id: a.hall_id, day_of_week: a.day, start_time: a.start_time, end_time: a.end_time,
            academic_term: a.academic_term,
          })),
        })
      if (r.ok) { setAdoptState('ok'); setAdoptMsg(`اعتُمدت المسودة بالكامل — ${r.created ?? result.assignments.length} محاضرة حُفظت بمعاملة واحدة.`) }
      else { setAdoptState('conflict'); setAdoptMsg((r.conflicts ?? []).map((c) => `تعارض في ${c.course_code ?? ''} — ${c.day} ${c.start_time?.slice(0,5)}: ${c.violations?.[0]?.message_ar ?? c.day}`).join(' · ') || (r.message_ar ?? 'تعارض — لم يُحفظ شيء')) }
    } catch (er) {
      setAdoptState('fail')
      setAdoptMsg((er as Error).message)
    } finally { setAdopting(false) }
  }

  if (ctxErr) return <div className="alert alert-danger">{ctxErr}</div>
  if (!ctx) return <div>جارٍ التحميل…</div>

  const totalSessions = mode === 'single'
    ? courseIds.reduce((sum, id) => sum + sessionsFor(id), 0)
    : ctx.groups.reduce((sum, g) => sum + (ctx.plans?.[g.id] ?? []).reduce((s, p) => s + p.sessions_per_week, 0), 0)

  const groupById = new Map(ctx.groups.map((g) => [g.id, g]))

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>اقتراح الجدول التلقائي (CP-SAT)</h1>
      <p className="small text-muted mb-3">مقترح للمراجعة فقط — لا يُحفظ شيء تلقائيًا؛ الاعتماد يمر بمعاملة واحدة عبر مسار check→save المعتاد (الكل أو لا شيء).</p>

      <form className="aust-panel p-3 mb-3" onSubmit={propose}>
        <div className="d-flex flex-wrap gap-3 align-items-center mb-3">
          <div className="d-flex gap-3">
            <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="pmode" checked={mode === 'single'} onChange={() => setMode('single')} /> شعبة واحدة</label>
            <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="pmode" checked={mode === 'all'} onChange={() => setMode('all')} /> كل الشعب ({ctx.groups.length}) حسب الخطط</label>
          </div>
          <div className="vr align-self-auto" />
          <div><label className="form-label" htmlFor="pt">الفصل</label><input id="pt" className="form-control" style={{ width: 110 }} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
          {mode === 'single' && (
            <div><label className="form-label" htmlFor="pg">الشعبة</label>
              <select id="pg" className="form-select" value={groupId ?? ''} onChange={(e) => setGroupId(Number(e.target.value))} required>
                {ctx.groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)}
              </select>
            </div>
          )}
          <button type="submit" className="aust-btn align-self-end" disabled={busy || (mode === 'single' && (!groupId || courseIds.length === 0))}>{busy ? 'جارٍ الحساب…' : 'اقترح الجدول'}</button>
        </div>

        {mode === 'single' ? (
          <div className="mt-2">
            <div className="d-flex justify-content-between align-items-center mb-1">
              <span className="small fw-bold">المقررات المشمولة ({courseIds.length}) — {totalSessions} حصة/أسبوع (الخطة الدراسية)</span>
              <span className="d-flex gap-2">
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => { const plan = groupId !== null ? (ctx.plans?.[groupId] ?? []) : []; setCourseIds(plan.length ? plan.map((p) => p.course_id) : ctx.courses.map((c) => c.id)) }}>طبق الخطة</button>
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => setCourseIds([])}>إلغاء الكل</button>
              </span>
            </div>
            <div className="d-flex flex-wrap gap-2" style={{ maxHeight: 220, overflowY: 'auto' }}>
              {ctx.courses.map((c) => (
                <label key={c.id} className="d-flex align-items-center gap-1 small" style={{ border: '1px solid #e5ded2', borderRadius: 8, padding: '2px 8px', cursor: 'pointer', background: courseIds.includes(c.id) ? '#fdf6ee' : '#fff' }}>
                  <input type="checkbox" checked={courseIds.includes(c.id)} onChange={() => toggleCourse(c.id)} />
                  <span>{c.code} — {c.name} <span className="text-muted">({sessionsFor(c.id)} حصص)</span></span>
                </label>
              ))}
            </div>
          </div>
        ) : (
          <div className="alert alert-light small mb-0">ستُحل كل شعبة ({ctx.groups.length}) بالتسلسل بخطة دراستها — مجموع الحصص المتوقعة: <strong>{totalSessions}</strong>. قد يستغرق حتى دقيقتين.</div>
        )}
      </form>

      {result && !result.ok && <div className="alert alert-danger">{result.message_ar ?? 'تعذر الاقتراح'}</div>}

      {result?.ok && (
        <>
          <div className="aust-panel p-3 mb-3 d-flex flex-wrap gap-3 align-items-center">
            <div><div className="small text-muted">حالة المحرك</div><strong style={{ color: '#782B19' }}>{result.multi ? 'BATCH (multi)' : result.status}</strong></div>
            <div><div className="small text-muted">حصص موزعة</div><strong style={{ color: '#0A1D3B' }}>{result.placed}</strong></div>
            <div><div className="small text-muted">متعذر</div><strong style={{ color: '#B26225' }}>{result.unplaced?.length ?? 0} مقرر</strong></div>
            <div className="d-flex gap-1 flex-wrap">
              {result.groups?.map((g) => (
                <span key={g.group_id} className="badge" title={`${g.batch} — ${g.track}`} style={{ background: g.ok ? '#1f7a3d' : '#B26225', color: '#fff' }}>
                  #{g.group_id}: {g.placed} حصة
                </span>
              ))}
            </div>
            <button type="button" className="aust-btn ms-auto" onClick={adoptAll} disabled={adopting || !result.assignments?.length || adoptState === 'ok'}>{adopting ? 'جارٍ الاعتماد…' : 'اعتماد الكل (معاملة واحدة)'}</button>
            <button type="button" className="aust-btn-outline" onClick={onAdopted}>عرض الجداول</button>
          </div>

          {adoptMsg && <div className={`alert ${adoptState === 'ok' ? 'alert-success' : 'alert-warning'}`}>{adoptMsg}</div>}

          <h2 className="h6 mb-2" style={{ color: '#782B19' }}>المسودة المقترحة ({result.assignments?.length ?? 0})</h2>
          <div className="table-responsive" style={{ maxHeight: 480, overflowY: 'auto' }}>
            <table className="table table-sm aust-panel">
              <thead className="table-light" style={{ position: 'sticky', top: 0 }}><tr><th>الشعبة</th><th>اليوم</th><th>التوقيت</th><th>المقرر</th><th>القاعة</th><th>المدرس</th><th>الحالة</th></tr></thead>
              <tbody>
                {result.assignments?.map((a, i) => (
                  <tr key={i}>
                    <td>#{a.group_id} <span className="text-muted small">{groupById.get(a.group_id)?.track}</span></td>
                    <td>{a.day}</td>
                    <td><span className="text-muted small">{a.label}</span> {a.start_time?.slice(0,5)}–{a.end_time?.slice(0,5)}</td>
                    <td><strong>{a.code}</strong> — {a.name}</td>
                    <td>{a.hall_name}</td>
                    <td>{a.faculty_name}</td>
                    <td>{adoptState === 'ok' ? '✓ اعتُمدت' : 'معلقة'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!!result.unplaced?.length && (
            <>
              <h2 className="h6 mb-2 mt-3" style={{ color: '#B26225' }}>تعذر توزيعها (راجع السعة/الطاقم/الفترات)</h2>
              <ul className="small">
                {result.unplaced.map((u, i) => <li key={i}><strong>{u.group_id ? `#${u.group_id} · ` : ''}{u.code} — {u.name}</strong>: {u.sessions} حصة — {u.reason}</li>)}
              </ul>
            </>
          )}
        </>
      )}
    </>
  )
}