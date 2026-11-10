import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

export default function TimetablesView({ call, onNew }: { call: ReturnType<typeof useAuthedApi>, onNew: () => void }) {
  const [groupId, setGroupId] = useState('5')
  const [groups, setGroups] = useState<{ id: number; batch: string; track: string; student_count: number }[]>([])
  const [rows, setRows] = useState<{id:number; course_id:number; faculty_id:number; group_id:number; hall_id:number; day_of_week:string; start_time:string; end_time:string}[]>([])
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    call<{ groups: { id: number; batch: string; track: string; student_count: number }[]; courses: unknown[] }>('/api/admin/propose-timetable/context')
      .then((d) => {
        setGroups(d.groups)
        setGroupId((cur) => (d.groups.some((g) => String(g.id) === cur) ? cur : String(d.groups[0]?.id ?? cur)))
      })
      .catch(() => { /* batch role: القائمة تبقى placeholder */ })
  }, [call])

  useEffect(() => {
    call<{id:number; course_id:number; faculty_id:number; group_id:number; hall_id:number; day_of_week:string; start_time:string; end_time:string}[]>(`/api/timetables?by=group&id=${groupId}`)
      .then(setRows)
      .catch((e) => setErr((e as Error).message))
  }, [groupId, call])

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>الجداول</h1>
        <button className="aust-btn" onClick={onNew}>+ إعداد الجدول</button>
      </div>
      <div className="aust-panel p-3 mb-3 d-flex gap-2">
        <label className="form-label m-0 align-self-center" htmlFor="grp">الشعبة:</label>
        <select id="grp" className="form-select" style={{maxWidth: 320}} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
          {groups.length > 0
            ? groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)
            : <option value={groupId}>الشعبة #{groupId}</option>}
        </select>
      </div>
      {err && <div className="alert alert-danger">{err}</div>}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>الشعبة</th><th>المقرر</th><th>اليوم</th><th>من</th><th>إلى</th><th>القاعة</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد محاضرات لهذه الشعبة</td></tr>}
          {rows.map((r) => (
            <tr key={r.id}><td>#{r.group_id}</td><td>#{r.course_id}</td><td>{r.day_of_week}</td><td>{r.start_time}</td><td>{r.end_time}</td><td>#{r.hall_id}</td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}