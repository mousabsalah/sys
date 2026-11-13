import { useCallback, useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type LectureMini = {
  id: number
  group_id: number
  course_id: number
  day_of_week: string
  start_time: string
  end_time: string
}

type SubstituteReq = {
  id: number
  status: 'pending' | 'accepted' | 'rejected' | 'cancelled'
  lecture: LectureMini | null
  requester: { id: number; full_name: string } | null
  substitute: { id: number; full_name: string } | null
}

type Payload = { outgoing: SubstituteReq[]; incoming: SubstituteReq[] }

const STATUS_LABEL: Record<SubstituteReq['status'], { text: string; bg: string }> = {
  pending: { text: 'معلّق', bg: '#B26225' },
  accepted: { text: 'مقبول', bg: '#1f7a3d' },
  rejected: { text: 'مرفوض', bg: '#782B19' },
  cancelled: { text: 'ملغي', bg: '#6c757d' },
}

function LectureRow({ lecture }: { lecture: LectureMini | null }) {
  if (!lecture) return <span className="text-muted">—</span>
  return <span>{lecture.day_of_week} · <span dir="ltr">{lecture.start_time.slice(0, 5)}–{lecture.end_time.slice(0, 5)}</span></span>
}

export default function SubstituteRequestsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<Payload | null>(null)
  const [tab, setTab] = useState<'incoming' | 'outgoing'>('incoming')
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(async () => {
    try { setData(await call<Payload>('/api/lecturer/substitute-requests')); setErr(null) }
    catch (e) { setErr((e as Error).message) }
  }, [call])

  useEffect(() => { load() }, [load])

  async function respond(id: number, action: 'accept' | 'reject') {
    setBusy(`respond:${id}`)
    setErr(null); setMsg(null)
    try {
      await call(`/api/lecturer/substitute-requests/${id}/respond`, 'POST', { action })
      setMsg(action === 'accept' ? 'قبلت الطلب — المحاضرة أُسندت إليك ✓' : 'رفضت الطلب')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(null) }
  }

  async function cancel(id: number) {
    if (!window.confirm('إلغاء طلب البديل؟')) return
    setBusy(`cancel:${id}`)
    setErr(null); setMsg(null)
    try {
      await call(`/api/lecturer/substitute-requests/${id}/cancel`, 'POST')
      setMsg('أُلغي الطلب')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(null) }
  }

  if (err && !data) return <ErrorRetry message={err} onRetry={load} />
  if (!data) return <SkeletonRows rows={4} height={56} />

  const list = tab === 'incoming' ? data.incoming : data.outgoing

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>طلبات البدلاء</h1>
      <p className="small text-muted mb-3">مدرس يطلب من زميل (نفس الشعبة) أن يغطي محاضرته — التفاوض مباشر بينكما، لا حاجة لموافقة المجلس.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={load} />}

      <ul className="nav nav-tabs mb-3">
        <li className="nav-item">
          <button className={`nav-link ${tab === 'incoming' ? 'active' : ''}`} onClick={() => setTab('incoming')}>
            واردة {data.incoming.filter((r) => r.status === 'pending').length > 0 && <span className="badge text-bg-danger ms-1">{data.incoming.filter((r) => r.status === 'pending').length}</span>}
          </button>
        </li>
        <li className="nav-item"><button className={`nav-link ${tab === 'outgoing' ? 'active' : ''}`} onClick={() => setTab('outgoing')}>صادرة ({data.outgoing.length})</button></li>
      </ul>

      <div className="d-flex flex-column gap-2">
        {list.map((r) => (
          <div key={r.id} className="aust-panel p-3 d-flex flex-wrap align-items-center gap-2">
            <div className="flex-grow-1">
              <div className="fw-bold"><LectureRow lecture={r.lecture} /></div>
              <div className="small text-muted">
                {tab === 'incoming'
                  ? <>من: <strong>{r.requester?.full_name ?? '—'}</strong> — يطلب منك تغطية المحاضرة</>
                  : <>إلى: <strong>{r.substitute?.full_name ?? '—'}</strong> — ردّ على طلبك أدناه</>}
              </div>
            </div>
            <span className="badge" style={{ background: STATUS_LABEL[r.status].bg, color: '#fff' }}>{STATUS_LABEL[r.status].text}</span>
            {tab === 'incoming' && r.status === 'pending' && (
              <div className="d-flex gap-1">
                <button className="aust-btn btn-sm" disabled={busy === `respond:${r.id}`} onClick={() => respond(r.id, 'accept')}>قبول</button>
                <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} disabled={busy === `respond:${r.id}`} onClick={() => respond(r.id, 'reject')}>رفض</button>
              </div>
            )}
            {tab === 'outgoing' && r.status === 'pending' && (
              <button className="aust-btn-outline btn-sm" disabled={busy === `cancel:${r.id}`} onClick={() => cancel(r.id)}>إلغاء الطلب</button>
            )}
          </div>
        ))}
        {list.length === 0 && <div className="alert alert-secondary mb-0">{tab === 'incoming' ? 'لا توجد طلبات واردة إليك' : 'لا توجد طلبات صادرة منك'}</div>}
      </div>
    </>
  )
}
