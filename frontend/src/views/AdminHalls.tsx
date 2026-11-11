import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type Hall = { id: number; name: string; capacity: number; hall_type: string; status: string; equipment?: string | null }
type Master = { halls: Hall[] }

const STATUS = ['متاحة', 'صيانة', 'مغلقة'] as const
const empty = { name: '', capacity: '50', hall_type: 'قاعة', status: 'متاحة' as Hall['status'], equipment: '' }

const statusColor: Record<string, string> = { 'متاحة': '#1f7a3d', 'صيانة': '#B26225', 'مغلقة': '#782B19' }

export default function AdminHallsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [halls, setHalls] = useState<Hall[]>([])
  const [form, setForm] = useState(empty)
  const [editing, setEditing] = useState<number | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function refresh() {
    try {
      const data = await call<Master>('/api/admin/master-data')
      setHalls(data.halls ?? [])
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { refresh() }, [])

  async function save() {
    setSaving(true); setErr(null); setMsg(null)
    const payload = { name: form.name, capacity: Number(form.capacity), hall_type: form.hall_type, status: form.status, equipment: form.equipment || null }
    try {
      if (editing !== null) {
        await call(`/api/admin/halls/${editing}`, 'PUT', payload)
        setMsg('تم تحديث القاعة ✓')
      } else {
        await call('/api/admin/halls', 'POST', payload)
        setMsg('تم إضافة القاعة ✓')
      }
      setForm(empty); setEditing(null)
      refresh()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function remove(h: Hall) {
    if (!window.confirm(`حذف قاعة «${h.name}»؟ الحذف ممنوع إذا كانت مرتبطة بمحاضرات أو امتحانات أو حجوزات.`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/halls/${h.id}`, 'DELETE')
      setMsg('تم حذف القاعة'); refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{ color: '#0A1D3B' }}>إدارة القاعات والمعامل</h1>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="aust-panel p-3 mb-3">
        <h2 className="h5 mb-2" style={{ color: '#782B19' }}>{editing !== null ? 'تعديل قاعة' : 'إضافة قاعة جديدة'}</h2>
        <div className="row g-2">
          <div className="col-md-3"><input className="form-control" placeholder="اسم القاعة (مدرج ج)" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="col-md-2"><input className="form-control" type="number" min={1} max={2000} placeholder="السعة" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} /></div>
          <div className="col-md-2">
            <select className="form-select" value={form.hall_type} onChange={(e) => setForm({ ...form, hall_type: e.target.value })}>
              <option value="قاعة">قاعة</option>
              <option value="معمل">معمل</option>
            </select>
          </div>
          <div className="col-md-2">
            <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              {STATUS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div className="col-md-2"><input className="form-control" placeholder="التجهيزات (اختياري)" value={form.equipment} onChange={(e) => setForm({ ...form, equipment: e.target.value })} /></div>
          <div className="col-md-1 d-flex gap-1">
            <button className="aust-btn flex-fill" disabled={saving || !form.name || !form.capacity} onClick={save}>{saving ? '…' : editing !== null ? 'حفظ' : '+'}</button>
          </div>
        </div>
        {editing !== null && <button className="aust-btn-outline btn-sm mt-2" onClick={() => { setEditing(null); setForm(empty) }}>إلغاء التعديل</button>}
      </div>

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>#</th><th>القاعة</th><th>النوع</th><th>السعة</th><th>الحالة</th><th>التجهيزات</th><th></th></tr></thead>
          <tbody>
            {halls.map((h) => (
              <tr key={h.id}>
                <td>#{h.id}</td>
                <td><strong>{h.name}</strong></td>
                <td>{h.hall_type}</td>
                <td>{h.capacity} مقعد</td>
                <td><span className="badge" style={{ background: statusColor[h.status] ?? '#555', color: '#fff' }}>{h.status}</span></td>
                <td className="small text-muted">{h.equipment ?? '—'}</td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => { setEditing(h.id); setForm({ name: h.name, capacity: String(h.capacity), hall_type: h.hall_type, status: h.status, equipment: h.equipment ?? '' }) }}>تعديل</button>
                  <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(h)}>حذف</button>
                </td>
              </tr>
            ))}
            {halls.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد قاعات</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
