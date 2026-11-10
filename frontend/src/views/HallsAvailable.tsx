import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

export default function HallsAvailableView({ call, onBook, canBook = true }: { call: ReturnType<typeof useAuthedApi>, onBook: (hallId: number) => void, canBook?: boolean }) {
  const today = '2026-11-10'
  const [date, setDate] = useState(today)
  const [from, setFrom] = useState('09:00:00')
  const [to, setTo] = useState('11:00:00')
  const [minCapacity, setMinCapacity] = useState('')
  const [halls, setHalls] = useState<{id:number; name:string; capacity:number; hall_type:string; equipment?:string}[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [queried, setQueried] = useState(false)

  async function search(e?: React.FormEvent) {
    e?.preventDefault()
    setBusy(true); setErr(null)
    try {
      const minCap = minCapacity ? String(minCapacity) : ''
      const data = await call<{query: Record<string, unknown>; halls: typeof halls; total: number}>(
        `/api/halls/available?date=${date}&from=${from}&to=${to}${minCap ? `&min_capacity=${minCap}` : ''}`
      )
      setHalls(data.halls || [])
      setQueried(true)
    } catch (er) { setErr((er as Error).message) }
    finally { setBusy(false) }
  }

  useEffect(() => { search() }, [])

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>القاعات المتاحة</h1>
      <form className="aust-panel p-3 d-flex flex-wrap gap-2 align-items-end" onSubmit={search}>
        <div><label className="form-label" htmlFor="d">التاريخ</label><input id="d" type="date" className="form-control" value={date} onChange={(e) => setDate(e.target.value)} required /></div>
        <div><label className="form-label" htmlFor="f">من</label><input id="f" type="time" step="1" className="form-control" value={from.slice(0, 5)} onChange={(e) => setFrom(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="t">إلى</label><input id="t" type="time" step="1" className="form-control" value={to.slice(0, 5)} onChange={(e) => setTo(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="mc">أقل سعة</label><input id="mc" type="number" className="form-control" placeholder="الكل" value={minCapacity} onChange={(e) => setMinCapacity(e.target.value)} /></div>
        <button type="submit" className="aust-btn" disabled={busy}>{busy ? 'جارٍ البحث…' : 'بحث'}</button>
      </form>

      {err && <div className="alert alert-danger mt-3">{err}</div>}
      {queried && halls.length === 0 && <div className="alert alert-warning mt-3">لا توجد قاعات متاحة بالمواصفات. جرّب سعة أقل أو فترة زمنية أخرى.</div>}

      <div className="row g-3 mt-2">
        {halls.map((h) => (
          <div className="col-md-4" key={h.id}>
            <div className="aust-panel p-3 h-100">
              <div className="d-flex justify-content-between align-items-start">
                <h2 className="h5 mb-1" style={{color: '#0A1D3B'}}>{h.name}</h2>
                <span className="badge" style={{background: '#D4822D', color: '#fff'}}>{h.hall_type}</span>
              </div>
              <p className="mb-1 small text-muted">سعتها: <strong>{h.capacity}</strong> مقعدًا</p>
              <p className="mb-2 small">{h.equipment ? h.equipment : <span className="text-muted">بلا تجهيزات</span>}</p>
              {canBook
                ? <button className="aust-btn-outline w-100" onClick={() => onBook(h.id)}>احجز</button>
                : <span className="small text-muted d-block text-center py-2">الاستعلام فقط — الحجز من المدير أو القسم</span>}
            </div>
          </div>
        ))}
      </div>
    </>
  )
}