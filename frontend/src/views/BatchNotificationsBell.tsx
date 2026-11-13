import { useEffect, useState } from 'react'
import { api } from '../api'

type Update = {
  id: number
  group_id: number
  timetable_id: number | null
  update_type: 'add' | 'edit' | 'cancel'
  description: string | null
  created_at: string
  track: string | null
  course_code: string | null
  course_name: string | null
  day_of_week: string | null
  start_time: string | null
  timetable_status: string | null
}

export default function BatchNotificationsBell() {
  const token = sessionStorage.getItem('aust_token')
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<Update[] | null>(null)
  const [err, setErr] = useState<string | null>(null)

  async function loadCount() {
    if (!token) return
    try {
      const d = await api<{ unread: number }>('/api/batch/notifications/count', 'GET', undefined, token)
      setUnread(d.unread)
    } catch { /* الجرس صامت عند خطأ — لا يشوّش الواجهة */ }
  }

  useEffect(() => {
    loadCount()
    const interval = window.setInterval(() => { if (document.visibilityState === 'visible') loadCount() }, 60_000)
    const onVisible = () => { if (document.visibilityState === 'visible') loadCount() }
    document.addEventListener('visibilitychange', onVisible)
    return () => { window.clearInterval(interval); document.removeEventListener('visibilitychange', onVisible) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  async function toggle() {
    if (open) { setOpen(false); return }
    setOpen(true)
    setItems(null)
    setErr(null)
    if (!token) return
    try {
      const d = await api<{ updates: Update[] }>('/api/batch/notifications', 'GET', undefined, token)
      setItems(d.updates)
    } catch (e) { setErr((e as Error).message) }
  }

  async function markAllRead() {
    if (!token) return
    try {
      await api('/api/batch/notifications/read', 'POST', undefined, token)
      setUnread(0)
    } catch (e) { setErr((e as Error).message) }
  }

  const typeColor = (t: Update['update_type']) => (t === 'cancel' ? '#782B19' : t === 'add' ? '#1f7a3d' : '#B26225')

  return (
    <div className="p-2 pt-0 position-relative">
      <button
        className="btn btn-sm w-100 position-relative"
        style={{ border: '1px solid #D4822D', color: '#D4822D', background: 'transparent' }}
        onClick={toggle}
        aria-label="إشعارات محاضرات دفعتك"
        aria-expanded={open}
      >
        🔔 إشعارات الدفعة
        {unread > 0 && (
          <span className="position-absolute top-0 start-0 translate-middle badge rounded-pill text-bg-danger" style={{ fontSize: 10 }}>
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          className="position-absolute bottom-100 start-0 m-2 p-2 aust-panel shadow"
          style={{ width: 340, maxHeight: 380, overflowY: 'auto', zIndex: 1050, background: '#fff', borderRadius: 8 }}
          role="dialog"
          aria-label="آخر تغييرات محاضرات دفعتك"
        >
          <div className="d-flex align-items-center justify-content-between mb-2">
            <strong style={{ color: '#0A1D3B' }}>آخر التغييرات</strong>
            <button className="btn btn-link btn-sm p-0" style={{ color: '#D4822D' }} onClick={markAllRead}>تحديد الكل كمقروء</button>
          </div>
          {err && <div className="alert alert-danger py-1 small">{err}</div>}
          {!err && items === null && <div className="text-muted small p-2">جارٍ التحميل…</div>}
          {!err && items !== null && items.length === 0 && <div className="text-muted small p-2">لا توجد تغييرات على محاضرات دفعتك حتى الآن.</div>}
          <div className="d-flex flex-column gap-1">
            {(items ?? []).map((u) => (
              <div key={u.id} className="border rounded p-2 small">
                <div className="d-flex justify-content-between align-items-center gap-2">
                  <div>
                    <span className="badge" style={{ background: typeColor(u.update_type), color: '#fff' }}>
                      {u.update_type === 'cancel' ? 'إلغاء' : u.update_type === 'add' ? 'إضافة' : 'تعديل'}
                    </span>{' '}
                    {u.course_name ? <span>{u.course_name}</span> : null}
                  </div>
                  <span className="text-muted" dir="ltr">{new Date(u.created_at).toLocaleDateString('ar-SD', { day: '2-digit', month: 'short' })}</span>
                </div>
                <div className="text-muted mt-1">
                  {u.track ? <span>شعبة {u.track} · </span> : null}
                  {u.day_of_week ? <span>{u.day_of_week} {u.start_time?.slice(0, 5)} · </span> : null}
                  {u.description}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
