import { useEffect, useState } from 'react'
import { BASE, useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

export default function ReportsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<{utilization: {hall_id:number; hall:string; capacity:number; lectures:number; utilization_pct:number}[]} | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  useEffect(() => {
    call<typeof data>('/api/reports/utilization')
      .then(setData)
      .catch((e) => setErr((e as Error).message))
  }, [reloadKey, call])
  if (err) return <ErrorRetry message={err} onRetry={() => { setErr(null); setReloadKey((k) => k + 1) }} />
  if (!data) return <SkeletonRows rows={5} height={40} />
  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>التقارير — نسبة استخدام القاعات</h1>
        <a className="aust-btn-outline" href={`${BASE}/api/reports/utilization?format=csv`} target="_blank" rel="noreferrer">تصدير CSV</a>
      </div>
      <table className="table table-sm aust-panel align-middle">
        <thead className="table-light"><tr><th>القاعة</th><th>السعة</th><th>عدد المحاضرات</th><th>نسبة الاستخدام</th></tr></thead>
        <tbody>
          {data.utilization.map((r) => (
            <tr key={r.hall_id}><td>{r.hall}</td><td>{r.capacity}</td><td>{r.lectures}</td><td><div className="progress" style={{height: 18}}><div className="progress-bar" style={{width: r.utilization_pct + '%', background: '#B26225'}}>{r.utilization_pct}%</div></div></td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}