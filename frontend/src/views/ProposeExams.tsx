import { useEffect, useState } from 'react'
import { type Call } from '../api'

type ExamProposalAssignment = {
  course_id: number; code: string; name: string; group_id: number
  exam_date: string; day: string; start_time: string; end_time: string; label: string
  hall_id: number; hall_name: string; academic_term: string
}
type ExamUnplaced = { course_id: number; code: string; name: string; sessions: number; reason: string; group_id?: number }
type ExamGroupSummary = { group_id: number; ok: boolean; status: string; placed: number; batch: string; track: string }
type ExamProposeResult = {
  ok: boolean; message_ar?: string
  status?: string; placed?: number; multi?: boolean
  groups?: ExamGroupSummary[]
  assignments?: ExamProposalAssignment[]; unplaced?: ExamUnplaced[]
}
type PlanItem = { course_id: number; code: string; name: string; sessions_per_week: number }
type ExamContext = {
  groups: { id: number; batch: string; track: string; student_count: number; program_id: number }[]
  courses: { id: number; code: string; name: string; credit_hours: number }[]
  plans: Record<number, PlanItem[]>
}

export default function ProposeExamsView({ call }: { call: Call }) {
  const [ctx, setCtx] = useState<ExamContext | null>(null)
  const [ctxErr, setCtxErr] = useState<string | null>(null)
  const [mode, setMode] = useState<'single' | 'all'>('single')
  const [groupId, setGroupId] = useState<number | null>(null)
  const [courseIds, setCourseIds] = useState<number[]>([])
  const [term, setTerm] = useState('2026/1')
  const [from, setFrom] = useState(() => { const d = new Date(); d.setDate(d.getDate() + 7); return d.toISOString().slice(0, 10) })
  const [to, setTo] = useState(() => { const d = new Date(); d.setDate(d.getDate() + 11); return d.toISOString().slice(0, 10) })
  const [result, setResult] = useState<ExamProposeResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [adopting, setAdopting] = useState(false)
  const [adoptState, setAdoptState] = useState<'idle' | 'ok' | 'conflict' | 'fail'>('idle')
  const [adoptMsg, setAdoptMsg] = useState<string | null>(null)

  useEffect(() => {
    call<ExamContext>('/api/council/propose-exams/context')
      .then((d) => { setCtx(d); setGroupId(d.groups[0]?.id ?? null) })
      .catch((e) => setCtxErr((e as Error).message))
  }, [call])

  // عند تغيير الشعبة — حمولة الخطة للشعبة (نفس سلوك مقترح المحاضرات)
  useEffect(() => {
    if (!ctx || groupId === null) return
    const plan = ctx.plans?.[groupId] ?? []
    setCourseIds(plan.map((p) => p.course_id))
  }, [ctx, groupId])

  async function propose(e: React.FormEvent) {
    e.preventDefault()
    if (mode === 'single' && (!groupId || courseIds.length === 0)) return
    if (!from || !to || to < from) { setResult({ ok: false, message_ar: 'مدى التواريخ غير صالح — «إلى» قبل «من»' }); return }
    setBusy(true); setResult(null); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const body = mode === 'single'
        ? { group_id: groupId, course_ids: courseIds, from, to, academic_term: term }
        : { group_ids: ctx?.groups.map((g) => g.id), from, to, academic_term: term }
      const r = await call<ExamProposeResult>('/api/council/propose-exams', 'POST', body)
      setResult(r)
    } catch (er) { setResult({ ok: false, message_ar: (er as Error).message }) }
    finally { setBusy(false) }
  }

  async function adoptAll() {
    if (!result?.assignments?.length) return
    setAdopting(true); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const r = await call<{ ok: boolean; created?: number; message_ar?: string; conflicts?: { index: number; course_code?: string; exam_date: string; start_time?: string; violations?: { message_ar: string }[] }[] }>(
        '/api/council/propose-exams/apply', 'POST', {
          assignments: result.assignments.map((a) => ({
            course_id: a.course_id, course_code: a.code, group_id: a.group_id,
            hall_id: a.hall_id, exam_date: a.exam_date, start_time: a.start_time, end_time: a.end_time,
            academic_term: a.academic_term,
          })),
        })
      if (r.ok) { setAdoptState('ok'); setAdoptMsg(`اعتُمدت المسودة بالكامل — ${r.created ?? result.assignments.length} امتحان «فاينل» حُفظت بمعاملة واحدة كمعلّقة — تظهر الآن للمدير في «اعتماد الجداول والامتحانات» (امتحانات معلّقة) للاعتماد، ولا تظهر للدفعة أو المدرس قبل ذلك.`) }
      else { setAdoptState('conflict'); setAdoptMsg((r.conflicts ?? []).map((c) => `تعارض في ${c.course_code ?? ''} — ${c.exam_date}: ${c.violations?.[0]?.message_ar ?? ''}`).join(' · ') || (r.message_ar ?? 'تعارض — لم يُحفظ شيء')) }
    } catch (er) {
      setAdoptState('fail')
      setAdoptMsg((er as Error).message)
    } finally { setAdopting(false) }
  }

  if (ctxErr) return <div className="alert alert-danger">{ctxErr}</div>
  if (!ctx) return <div>جارٍ التحميل…</div>

  const totalExams = mode === 'single'
    ? courseIds.length
    : ctx.groups.reduce((sum, g) => sum + (ctx.plans?.[g.id] ?? []).length, 0)

  const groupById = new Map(ctx.groups.map((g) => [g.id, g]))

  return (
    <>
      <h1 className="h3 fw-bold mb-1 aust-h1">اقتراح الامتحانات التلقائي (CP-SAT)</h1>
      <p className="small text-muted mb-3">فاينل لكل مقرر في الخطة الدراسية على تواريخ حقيقية ضمن المدى — قاعة بامتحان واحد في نفس الوقت، الشعبة بامتحان واحد فقط في نفس الوقت، وسعة القاعة تكفي للطلبة. الملغية وكل ما قبله لا تُقتبس مرة ثانية. المسودة للمراجعة — الإنشاء الفعلي يمر بالكامل بعد «اعتماد الكل» وتظهر للأدمن «اعتماد الجداول والامتحانات» قبل أن تظهر للجميع.</p>

      <form className="aust-panel p-3 mb-3" onSubmit={propose}>
        <div className="d-flex flex-wrap gap-3 align-items-end mb-3">
          <div className="d-flex flex-column gap-1">
            <span className="small fw-bold">النطاق</span>
            <div className="d-flex gap-3">
              <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="emode" checked={mode === 'single'} onChange={() => setMode('single')} /> شعبة واحدة</label>
              <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="emode" checked={mode === 'all'} onChange={() => setMode('all')} /> كل الشعب ({ctx.groups.length}) حسب خططها</label>
            </div>
          </div>
          <div><label className="form-label" htmlFor="et">الفصل</label><input id="et" className="form-control" style={{ width: 110 }} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
          <div><label className="form-label" htmlFor="ef">من تاريخ</label><input id="ef" type="date" className="form-control" value={from} onChange={(e) => setFrom(e.target.value)} required /></div>
          <div><label className="form-label" htmlFor="ee">إلى تاريخ</label><input id="ee" type="date" className="form-control" value={to} onChange={(e) => setTo(e.target.value)} required /></div>
          {mode === 'single' && (
            <div><label className="form-label" htmlFor="eg">الشعبة</label>
              <select id="eg" className="form-select" value={groupId ?? ''} onChange={(e) => setGroupId(Number(e.target.value))} required>
                {ctx.groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)}
              </select>
            </div>
          )}
          <button type="submit" className="aust-btn align-self-end" disabled={busy || (mode === 'single' && (!groupId || courseIds.length === 0))}>{busy ? 'جارٍ الحساب…' : 'اقترح الامتحانات'}</button>
        </div>
        <div className="small text-muted">الفترات ٣ ساعات (صباحًا 09:00–12:00 · مساءً 12:30–15:30) — الجمعة مستبعدة (عطلة رسمية).</div>

        {mode === 'single' ? (
          <div className="mt-2">
            <div className="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
              <span className="small fw-bold">المقررات المشمولة ({courseIds.length}) — فاينل لكل مقرر (الخطة الدراسية)</span>
              <span className="d-flex gap-2">
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => { const plan = groupId !== null ? (ctx.plans?.[groupId] ?? []) : []; setCourseIds(plan.length ? plan.map((p) => p.course_id) : ctx.courses.map((c) => c.id)) }}>طبق الخطة</button>
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => setCourseIds([])}>إلغاء الكل</button>
              </span>
            </div>
            <div className="d-flex flex-wrap gap-2" style={{ maxHeight: 220, overflowY: 'auto' }}>
              {ctx.courses.map((c) => (
                <label key={c.id} className="d-flex align-items-center gap-1 small" style={{ border: '1px solid #e5ded2', borderRadius: 8, padding: '2px 8px', cursor: 'pointer', background: courseIds.includes(c.id) ? '#fdf6ee' : '#fff' }}>
                  <input type="checkbox" checked={courseIds.includes(c.id)} onChange={() => setCourseIds((prev) => (prev.includes(c.id) ? prev.filter((x) => x !== c.id) : [...prev, c.id]))} />
                  <span>{c.code} — {c.name}</span>
                </label>
              ))}
            </div>
          </div>
        ) : (
          <div className="alert alert-light small mt-2 mb-0">ستُحل كل شعبة ({ctx.groups.length}) بالتسلسل على نطاق التواريخ نفسه — مجموع المقررات المتوقع امتحانها: <strong>{totalExams}</strong> (فاينل لكل مقرر).</div>
        )}
      </form>

      {result && !result.ok && <div className="alert alert-danger">{result.message_ar ?? 'تعذر الاقتراح'}</div>}

      {result?.ok && (
        <>
          <div className="aust-panel p-3 mb-3 d-flex flex-wrap gap-3 align-items-center">
            <div><div className="small text-muted">حالة المحرك</div><strong style={{ color: '#782B19' }}>{result.multi ? 'BATCH (multi)' : result.status}</strong></div>
            <div><div className="small text-muted">امتحانات موزعة</div><strong style={{ color: '#0A1D3B' }}>{result.placed}</strong></div>
            <div><div className="small text-muted">متعذر</div><strong style={{ color: '#B26225' }}>{result.unplaced?.length ?? 0} مقرر</strong></div>
            <div className="d-flex gap-1 flex-wrap">
              {result.groups?.map((g) => (
                <span key={g.group_id} className="badge" title={`${g.batch} — ${g.track}`} style={{ background: g.ok ? '#1f7a3d' : '#B26225', color: '#fff' }}>#{g.group_id}: {g.placed} فاينل</span>
              ))}
            </div>
            <button type="button" className="aust-btn ms-auto" onClick={adoptAll} disabled={adopting || !result.assignments?.length || adoptState === 'ok'}>{adopting ? 'جارٍ الاعتماد…' : 'اعتماد الكل (معاملة واحدة)'}</button>
          </div>

          {adoptMsg && <div className={`alert ${adoptState === 'ok' ? 'alert-success' : 'alert-warning'}`}>{adoptMsg}</div>}

          <h2 className="h6 mb-2" style={{ color: '#782B19' }}>المسودة المقترحة ({result.assignments?.length ?? 0})</h2>
          <div className="table-responsive" style={{ maxHeight: 520, overflowY: 'auto' }}>
            <table className="table table-sm aust-panel">
              <thead className="table-light" style={{ position: 'sticky', top: 0 }}><tr><th>الشعبة</th><th>التاريخ</th><th>اليوم</th><th>الفترة</th><th>المقرر</th><th>القاعة</th><th>الحالة</th></tr></thead>
              <tbody>
                {result.assignments?.map((a, i) => (
                  <tr key={i}>
                    <td>#{a.group_id} <span className="text-muted small">{groupById.get(a.group_id)?.track}</span></td>
                    <td dir="ltr">{a.exam_date}</td>
                    <td>{a.day}</td>
                    <td>{a.label} <span className="text-muted small">{a.start_time?.slice(0, 5)}–{a.end_time?.slice(0, 5)}</span></td>
                    <td><strong>{a.code}</strong> — {a.name}</td>
                    <td>{a.hall_name}</td>
                    <td>{adoptState === 'ok' ? '✓ اعتمد الفاينل (معلّقة بانتظار المدير)' : 'معلقة'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!!result.unplaced?.length && (
            <>
              <h2 className="h6 mb-2 mt-3" style={{ color: '#B26225' }}>تعذر توزيعها (راجع السعة/القاعات/المدى)</h2>
              <ul className="small">
                {result.unplaced.map((u, i) => <li key={i}><strong>{u.group_id ? `#${u.group_id} · ` : ''}{u.code} — {u.name}</strong>: {u.sessions} امتحان — {u.reason}</li>)}
              </ul>
            </>
          )}
        </>
      )}
    </>
  )
}
