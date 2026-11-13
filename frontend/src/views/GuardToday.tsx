import { useEffect, useRef, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonCards, ErrorRetry } from '../components/LoadState'

type Entry = {
  kind: 'lecture' | 'exam'
  id: number
  hall_id: number
  start_time: string
  end_time: string
  status: string
  label: string
  course_code: string | null
  course_name: string | null
  group: string
  with: string | null
  state: 'ended' | 'upcoming' | 'ongoing'
}

type HallToday = {
  id: number
  name: string
  hall_type: string
  capacity: number
  equipment: string | null
  status: string | null
  entries: Entry[]
}

type Payload = {
  now: string
  day: string
  date: string
  total_halls: number
  busy_now: number
  free_now: number
  halls: HallToday[]
}

export default function GuardTodayView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<Payload | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [lastUpdated, setLastUpdated] = useState<string | null>(null)
  const [filter, setFilter] = useState<'all' | 'upcoming' | 'empty'>('all')
  const seqRef = useRef(0)

  async function refresh() {
    const seq = ++seqRef.current
    setErr(null)
    try {
      const d = await call<Payload>('/api/guard/today')
      if (seq !== seqRef.current) return
      setData(d)
      setLastUpdated(new Date().toLocaleTimeString('ar-SD', { hour: '2-digit', minute: '2-digit', second: '2-digit' }))
    } catch (e) {
      if (seq === seqRef.current) setErr((e as Error).message)
    } finally {
      if (seq === seqRef.current) setLoading(false)
    }
  }

  // تحميل أولي + تحديث تلقائي كل 30 ثانية (فقط والصفحة مرئية)
  useEffect(() => {
    refresh()
    const interval = window.setInterval(() => { if (document.visibilityState === 'visible') refresh() }, 30_000)
    const onVisible = () => { if (document.visibilityState === 'visible') refresh() }
    document.addEventListener('visibilitychange', onVisible)
    return () => { window.clearInterval(interval); document.removeEventListener('visibilitychange', onVisible) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [call])

  if (err && !data) return <ErrorRetry message={err} onRetry={refresh} />
  if (loading && !data) return <SkeletonCards count={6} />

  const halls = (data?.halls ?? []).filter((h) =>
    filter === 'all' ? true : filter === 'upcoming' ? h.entries.some((e) => e.state === 'upcoming') : h.entries.length === 0,
  )

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>قاعات اليوم — شاشة الحارس</h1>
      <p className="small text-muted mb-3">
        {data?.day} {data?.date} · تُحدَّث تلقائيًا كل 30 ثانية{lastUpdated ? <> — آخر تحديث <strong>{lastUpdated}</strong></> : null}
      </p>

      {err && <ErrorRetry message={err} onRetry={refresh} />}

      <div className="aust-panel p-3 mb-3 d-flex align-items-center gap-3 flex-wrap">
        <span className="badge fs-6" style={{ background: '#1f7a3d', color: '#fff' }}>{data?.free_now ?? 0} قاعة حرة الآن</span>
        <span className="small text-muted">من إجمالي {data?.total_halls ?? 0} قاعة ({data?.busy_now ?? 0} مشغولة الآن لا تُعرض)</span>
        <div className="ms-auto d-flex gap-1" role="group" aria-label="تصفية القاعات">
          <button className={`btn btn-sm ${filter === 'all' ? 'btn-dark' : 'btn-outline-dark'}`} onClick={() => setFilter('all')}>الكل</button>
          <button className={`btn btn-sm ${filter === 'upcoming' ? 'btn-dark' : 'btn-outline-dark'}`} onClick={() => setFilter('upcoming')}>بها شيء قادم اليوم</button>
          <button className={`btn btn-sm ${filter === 'empty' ? 'btn-dark' : 'btn-outline-dark'}`} onClick={() => setFilter('empty')}>يومها فارغ</button>
        </div>
      </div>

      {halls.length === 0 && (
        <div className="alert alert-secondary">
          {filter === 'all'
            ? 'كل القاعات مشغولة الآن بمحاضرة أو امتحان — لا توجد قاعة حرة الآن.'
            : filter === 'upcoming'
              ? 'لا توجد قاعة حرة بها محاضرة/امتحان قادم اليوم.'
              : 'لا توجد قاعة حرة بيومها فارغ تمامًا.'}
        </div>
      )}

      <div className="row g-3">
        {halls.map((h) => (
          <div className="col-md-6" key={h.id}>
            <div className="aust-panel p-3 h-100">
              <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                <div>
                  <h2 className="h5 mb-0" style={{ color: '#0A1D3B' }}>{h.name}</h2>
                  <div className="small text-muted">سعتها <strong>{h.capacity}</strong> مقعد · {h.hall_type}</div>
                </div>
                <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>فارغة الآن</span>
              </div>
              <p className="small mb-2">{h.equipment ? h.equipment : <span className="text-muted">بلا تجهيزات</span>}</p>

              {h.entries.length === 0 ? (
                <div className="small text-muted border rounded p-2">يومها فارغ — لا محاضرات ولا امتحانات اليوم.</div>
              ) : (
                <table className="table table-sm mb-0 align-middle aust-table">
                  <thead className="table-light"><tr><th>الوقت</th><th>النوع</th><th>المقرر / المجموعة</th><th>الحالة</th></tr></thead>
                  <tbody>
                    {h.entries.map((e) => {
                      const cancelled = e.status === 'cancelled'
                      return (
                        <tr key={`${e.kind}-${e.id}`} style={cancelled ? { opacity: 0.55 } : undefined}>
                          <td dir="ltr" className="small">{e.start_time.slice(0, 5)}–{e.end_time.slice(0, 5)}</td>
                          <td className="small">{cancelled
                            ? <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>{e.label} — ملغية</span>
                            : <span className="badge" style={{ background: e.kind === 'exam' ? '#782B19' : '#0A1D3B', color: '#fff' }}>{e.label}</span>}
                          </td>
                          <td className="small">
                            <span dir="ltr" className="badge text-bg-light border">{e.course_code}</span> {e.course_name}
                            <div className="text-muted">{e.group}{e.with ? ` · ${e.with}` : ''}</div>
                          </td>
                          <td>{e.state === 'ended'
                            ? <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>انتهت</span>
                            : <span className="badge" style={{ background: '#B26225', color: '#fff' }}>قادمة {e.start_time.slice(0, 5)}</span>}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        ))}
      </div>
    </>
  )
}
