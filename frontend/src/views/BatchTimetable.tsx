import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type Group = { id: number; batch: string; track: string; student_count: number }
type Row = {
  id: number
  group_id: number
  day_of_week: string
  start_time: string
  end_time: string
  academic_term: string
  status: 'approved' | 'cancelled'
  course_code: string | null
  course_name: string | null
  track: string | null
  hall_name: string | null
  faculty_name: string | null
}
type Payload = { batch: { id: number; name: string }; groups: Group[]; rows: Row[] }

export default function BatchTimetableView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<Payload | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [groupId, setGroupId] = useState<number | 'all'>('all')

  async function load() {
    setLoading(true)
    try { setData(await call<Payload>('/api/batch/timetables')); setErr(null) }
    catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [call])

  if (err && !data) return <ErrorRetry message={err} onRetry={load} />
  if (loading && !data) return <SkeletonRows rows={6} height={40} />

  const all = data?.rows ?? []
  const groups = data?.groups ?? []
  const rows = groupId === 'all' ? all : all.filter((r) => r.group_id === groupId)
  const cancelledCount = all.filter((r) => r.status === 'cancelled').length

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>جدولي — {data?.batch.name}</h1>
      <p className="small text-muted mb-3">جدول محاضرات شعب دفعتك المعتمدة فقط (بالترتيب الزمني). المحاضرات الملغية تبقى ظاهرة بشارة رمادية حتى لا يفوتك التغيير.</p>
      {err && <ErrorRetry message={err} onRetry={load} />}

      {cancelledCount > 0 && (
        <div className="alert alert-warning py-2">
          🔔 تنبيه: {cancelledCount === 1 ? 'محاضرة واحدة ملغية' : `${cancelledCount} محاضرات ملغية`} في جدول دفعتك — راجع الجرس في الأسفل لآخر التغييرات.
        </div>
      )}

      <div className="aust-panel p-2 mb-3 d-flex gap-1 flex-wrap" role="group" aria-label="تصفية الشعب">
        <button className={`btn btn-sm ${groupId === 'all' ? 'btn-dark' : 'btn-outline-dark'}`} onClick={() => setGroupId('all')}>كل الشعب ({all.length})</button>
        {groups.map((g) => (
          <button key={g.id} className={`btn btn-sm ${groupId === g.id ? 'btn-dark' : 'btn-outline-dark'}`} onClick={() => setGroupId(g.id)}>
            {g.track} ({all.filter((r) => r.group_id === g.id).length})
          </button>
        ))}
      </div>

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>اليوم</th><th>من</th><th>إلى</th><th>الشعبة</th><th>المقرر</th><th>القاعة</th><th>المدرس</th><th>الحالة</th></tr></thead>
          <tbody>
            {rows.map((r) => {
              const cancelled = r.status === 'cancelled'
              return (
                <tr key={r.id} style={cancelled ? { opacity: 0.55 } : undefined}>
                  <td>{r.day_of_week}</td>
                  <td dir="ltr">{r.start_time.slice(0, 5)}</td>
                  <td dir="ltr">{r.end_time.slice(0, 5)}</td>
                  <td className="small">{r.track ?? `شعبة #${r.group_id}`}</td>
                  <td><span dir="ltr" className="badge text-bg-light border">{r.course_code}</span> {r.course_name}</td>
                  <td>{r.hall_name ?? '—'}</td>
                  <td>{r.faculty_name ?? '—'}</td>
                  <td>{cancelled
                    ? <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>ملغية</span>
                    : <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>معتمدة</span>}
                  </td>
                </tr>
              )
            })}
            {rows.length === 0 && <tr><td colSpan={8} className="text-muted">لا توجد محاضرات في هذه الشعبة — راجع إدارة الأقسام أو مجلس المدرسين</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
