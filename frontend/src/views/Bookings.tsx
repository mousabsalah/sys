import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

export default function BookingsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [rows, setRows] = useState<{id:number; hall_id:number; date:string; start_time:string; end_time:string; activity_name?:string}[]>([])
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => { call<typeof rows>('/api/bookings?by=date&id=2026-11-10').then(setRows).catch((e) => setErr((e as Error).message)) }, [call])
  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>الحجوزات</h1>
      {err && <div className="alert alert-danger">{err}</div>}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>القاعة</th><th>التاريخ</th><th>من</th><th>إلى</th><th>النشاط</th></tr></thead>
        <tbody>{rows.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد حجوزات</td></tr>}{rows.map((b) => (<tr key={b.id}><td>#{b.hall_id}</td><td>{b.date}</td><td>{b.start_time}</td><td>{b.end_time}</td><td>{b.activity_name ?? '—'}</td></tr>))}</tbody>
      </table>
    </>
  )
}