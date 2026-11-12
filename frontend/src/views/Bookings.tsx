import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

export default function BookingsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [rows, setRows] = useState<{id:number; hall_id:number; date:string; start_time:string; end_time:string; activity_name?:string}[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  useEffect(() => {
    setLoading(true)
    call<typeof rows>('/api/bookings')
      .then(setRows)
      .catch((e) => setErr((e as Error).message))
      .finally(() => setLoading(false))
  }, [reloadKey, call])
  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>الحجوزات</h1>
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>القاعة</th><th>التاريخ</th><th>من</th><th>إلى</th><th>النشاط</th></tr></thead>
        <tbody>{loading && <tr><td colSpan={5}><SkeletonRows rows={3} height={32} /></td></tr>}{!loading && rows.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد حجوزات</td></tr>}{rows.map((b) => (<tr key={b.id}><td>#{b.hall_id}</td><td>{b.date}</td><td>{b.start_time}</td><td>{b.end_time}</td><td>{b.activity_name ?? '—'}</td></tr>))}</tbody>
      </table>
    </>
  )
}