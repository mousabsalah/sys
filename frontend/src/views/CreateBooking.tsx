import { useState } from 'react'
import { useAuthedApi } from '../api'

export default function CreateBookingView({ call, onDone, hallId }: { call: ReturnType<typeof useAuthedApi>, onDone: () => void, hallId: number | null }) {
  const [date, setDate] = useState('2026-11-10')
  const [from, setFrom] = useState('13:00:00')
  const [to, setTo] = useState('15:00:00')
  const [activityName, setActivityName] = useState('')
  const [courseId, setCourseId] = useState('')
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!hallId) { setMsg('اختر قاعة من «القاعات المتاحة» أولًا'); return }
    setBusy(true); setMsg(null)
    try {
      const payload: Record<string, unknown> = { hall_id: hallId, date, start_time: from, end_time: to }
      if (activityName.trim()) payload.activity_name = activityName.trim()
      if (courseId.trim()) payload.course_id = Number(courseId)
      const r = await call<{ok: boolean; message_ar?: string}>('/api/bookings', 'POST', payload)
      if (r.ok) { setSaved(true); setMsg('تم الحجز ✓'); setTimeout(onDone, 800) }
      else { setMsg(r.message_ar ?? 'تعارض') }
    } catch (er) { setMsg((er as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>حجز قاعة</h1>
      <div className="aust-panel p-2 p-md-3 d-inline-flex align-items-center gap-2 mb-3">
        <span className="small text-muted">القاعة المختارة:</span>
        <strong>{hallId ? `قاعة #${hallId}` : 'لم تُحدَّد — اذهب إلى «القاعات المتاحة» لاختيارها'}</strong>
      </div>

      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={save}>
        <div><label className="form-label" htmlFor="d">التاريخ</label><input id="d" type="date" className="form-control" value={date} onChange={(e) => setDate(e.target.value)} required /></div>
        <div><label className="form-label" htmlFor="f">من</label><input id="f" type="time" step="1" className="form-control" value={from.slice(0, 5)} onChange={(e) => setFrom(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="t">إلى</label><input id="t" type="time" step="1" className="form-control" value={to.slice(0, 5)} onChange={(e) => setTo(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="ac">النشاط</label><input id="ac" className="form-control" placeholder="اجتماع، ورشة، يوم مفتوح…" value={activityName} onChange={(e) => setActivityName(e.target.value)} /></div>
        <div><label className="form-label" htmlFor="ci">أو مقرر #</label><input id="ci" type="number" className="form-control" placeholder="اختياري" value={courseId} onChange={(e) => setCourseId(e.target.value)} /></div>
        <div className="d-flex align-items-end"><button type="submit" className="aust-btn w-100" disabled={busy || saved}>{saved ? 'تم الحجز ✓' : busy ? 'جارٍ الحجز…' : 'تأكيد الحجز'}</button></div>
      </form>

      {msg && <div className="alert alert-info mt-3">{msg}</div>}
    </>
  )
}