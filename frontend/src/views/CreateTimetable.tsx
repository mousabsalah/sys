import { useState } from 'react'
import { useAuthedApi } from '../api'

export default function CreateTimetableView({ call, onDone }: { call: ReturnType<typeof useAuthedApi>, onDone: () => void }) {
  const [form, setForm] = useState({ course_id: 1, faculty_id: 1, group_id: 1, hall_id: 1, day_of_week: 'السبت', start_time: '11:30:00', end_time: '13:30:00', academic_term: '2026/1' })
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [verdict, setVerdict] = useState<{conflict: boolean; violations: {code: string; message_ar: string; severity: string}[]} | null>(null)

  async function check(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null); setVerdict(null)
    try {
      const r = await call<{ok: boolean; verdict?: typeof verdict}>('/api/timetables/check', 'POST', form)
      setVerdict(r.verdict ?? { conflict: !r.ok, violations: [] })
      setMsg(r.verdict?.conflict ? 'تعارض — لم يتم الحفظ' : 'لا يوجد تعارض ✓ — متاح الحفظ')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  async function save() {
    setBusy(true); setMsg(null)
    try {
      const r = await call<{ok: boolean; message_ar?: string}>('/api/timetables', 'POST', form)
      if (r.ok) { setMsg('تم الحفظ ✓'); setTimeout(onDone, 800) } else setMsg(r.message_ar ?? 'تعارض')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>إعداد الجدول</h1>
      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={check}>
        <div><label className="form-label" htmlFor="c">المقرر</label><input id="c" type="number" className="form-control" value={form.course_id} onChange={(e) => setForm({ ...form, course_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="f">عضو هيئة التدريس</label><input id="f" type="number" className="form-control" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="g">الدفعة/الشعبة</label><input id="g" type="number" className="form-control" value={form.group_id} onChange={(e) => setForm({ ...form, group_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="h">القاعة</label><input id="h" type="number" className="form-control" value={form.hall_id} onChange={(e) => setForm({ ...form, hall_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="d">اليوم</label><select id="d" className="form-select" value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value })}><option>السبت</option><option>الأحد</option><option>الاثنين</option><option>الثلاثاء</option><option>الأربعاء</option><option>الخميس</option></select></div>
        <div><label className="form-label" htmlFor="s">من</label><input id="s" type="time" step="1" className="form-control" value={form.start_time.slice(0, 5)} onChange={(e) => setForm({ ...form, start_time: e.target.value + ':00' })} /></div>
        <div><label className="form-label" htmlFor="e2">إلى</label><input id="e2" type="time" step="1" className="form-control" value={form.end_time.slice(0, 5)} onChange={(e) => setForm({ ...form, end_time: e.target.value + ':00' })} /></div>
        <div className="d-flex align-items-end gap-2"><button type="submit" className="aust-btn-outline" disabled={busy}>تحقق من التعارض</button>
          <button type="button" className="aust-btn" onClick={save} disabled={busy}>حفظ في الجدول</button></div>
      </form>
      {verdict && (
        <div className="aust-panel p-3 mt-3">
          <h2 className="h5" style={{color: '#782B19'}}>نتيجة التحقق</h2>
          {verdict.violations.length === 0 && <div className="text-success fw-bold">لا تعارض ✓</div>}
          <ul className="mb-0">{verdict.violations.map((v, i) => (<li key={i} className={v.severity === 'hard' ? 'text-danger' : 'text-warning'}><strong>{v.message_ar}</strong> <code>{v.code}</code></li>))}</ul>
        </div>
      )}
      {msg && <div className="alert alert-info mt-3">{msg}</div>}
    </>
  )
}