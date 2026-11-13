import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type Row = {
  id: number
  group_id: number
  exam_date: string
  start_time: string
  end_time: string
  exam_type: string | null
  academic_term: string
  status: 'approved' | 'cancelled'
  course_code: string | null
  course_name: string | null
  track: string | null
  hall_name: string | null
}
type Payload = { batch: { id: number; name: string }; rows: Row[] }

export default function BatchExamsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<Payload | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  async function load() {
    setLoading(true)
    try { setData(await call<Payload>('/api/batch/exams')); setErr(null) }
    catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [call])

  if (err && !data) return <ErrorRetry message={err} onRetry={load} />
  if (loading && !data) return <SkeletonRows rows={5} height={40} />

  const rows = data?.rows ?? []
  const today = new Date().toISOString().slice(0, 10)

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>جدول الامتحانات — {data?.batch.name}</h1>
      <p className="small text-muted mb-3">امتحانات شعب دفعتك المعتمدة فقط، مرتبة حسب التاريخ.</p>
      {err && <ErrorRetry message={err} onRetry={load} />}

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>التاريخ</th><th>الوقت</th><th>الشعبة</th><th>المقرر</th><th>القاعة</th><th>النوع</th><th>الحالة</th></tr></thead>
          <tbody>
            {rows.map((r) => {
              const cancelled = r.status === 'cancelled'
              const isPast = String(r.exam_date).slice(0, 10) < today
              return (
                <tr key={r.id} style={cancelled ? { opacity: 0.55 } : undefined}>
                  <td dir="ltr">{String(r.exam_date).slice(0, 10)}{isPast ? <span className="text-muted small"> (انتهى)</span> : null}</td>
                  <td dir="ltr">{r.start_time.slice(0, 5)}–{r.end_time.slice(0, 5)}</td>
                  <td className="small">{r.track ?? `شعبة #${r.group_id}`}</td>
                  <td><span dir="ltr" className="badge text-bg-light border">{r.course_code}</span> {r.course_name}</td>
                  <td>{r.hall_name ?? '—'}</td>
                  <td>{r.exam_type ?? '—'}</td>
                  <td>{cancelled
                    ? <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>ملغي</span>
                    : <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>معتمد</span>}
                  </td>
                </tr>
              )
            })}
            {rows.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد امتحانات معتمدة لدفعتك بعد — تابع الجرس أو تواصل مع مجلس المدرسين</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
