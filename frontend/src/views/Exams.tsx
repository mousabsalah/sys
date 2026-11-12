import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

export default function ExamsView({ call, onNew }: { call: ReturnType<typeof useAuthedApi>, onNew: () => void }) {
  const [groupId, setGroupId] = useState('1')
  const [rows, setRows] = useState<{id:number; course_id:number; group_id:number; hall_id:number; exam_date:string; start_time:string; end_time:string; exam_type:string}[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    setLoading(true)
    call<{id:number; course_id:number; group_id:number; hall_id:number; exam_date:string; start_time:string; end_time:string; exam_type:string}[]>(`/api/exams?by=group&id=${groupId}`)
      .then(setRows)
      .catch((e) => setErr((e as Error).message))
      .finally(() => setLoading(false))
  }, [groupId, reloadKey, call])

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>الامتحانات</h1>
        <button className="aust-btn" onClick={onNew}>+ إعداد امتحان</button>
      </div>
      <div className="aust-panel p-3 mb-3 d-flex gap-2">
        <label className="form-label m-0 align-self-center" htmlFor="ge">الدفعة:</label>
        <select id="ge" className="form-select" style={{maxWidth: 200}} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
          <option value="1">الدفعة 1</option>
          <option value="2">الدفعة 2</option>
          <option value="3">الدفعة 3</option>
          <option value="4">الدفعة 4</option>
        </select>
      </div>
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>الشعبة</th><th>المقرر</th><th>التاريخ</th><th>من</th><th>إلى</th><th>القاعة</th><th>النوع</th></tr></thead>
        <tbody>
          {loading && <tr><td colSpan={7}><SkeletonRows rows={3} height={32} /></td></tr>}
          {!loading && rows.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد امتحانات لهذه الشعبة</td></tr>}
          {rows.map((r) => (<tr key={r.id}><td>#{r.group_id}</td><td>#{r.course_id}</td><td>{r.exam_date}</td><td>{r.start_time}</td><td>{r.end_time}</td><td>#{r.hall_id}</td><td>{r.exam_type}</td></tr>))}
        </tbody>
      </table>
    </>
  )
}