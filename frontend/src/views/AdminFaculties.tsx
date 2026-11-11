import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type Faculty = { id: number; name: string; code: string; description?: string | null }
type Master = { faculties: Faculty[] }

const empty = { name: '', code: '', description: '' }

export default function AdminFacultiesView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [form, setForm] = useState(empty)
  const [editing, setEditing] = useState<number | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function refresh() {
    try {
      const data = await call<Master>('/api/admin/master-data')
      setFaculties(data.faculties ?? [])
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { refresh() }, [])

  async function save() {
    setSaving(true); setErr(null); setMsg(null)
    try {
      if (editing !== null) {
        await call(`/api/admin/faculties/${editing}`, 'PUT', form)
        setMsg('تم تحديث الكلية ✓')
      } else {
        await call('/api/admin/faculties', 'POST', form)
        setMsg('تم إضافة الكلية ✓')
      }
      setForm(empty); setEditing(null)
      refresh()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function remove(f: Faculty) {
    if (!window.confirm(`حذف كلية «${f.name}»؟ الحذف ممنوع إذا كانت بها أقسام.`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/faculties/${f.id}`, 'DELETE')
      setMsg('تم حذف الكلية'); refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{ color: '#0A1D3B' }}>إدارة الكليات</h1>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="aust-panel p-3 mb-3">
        <h2 className="h5 mb-2" style={{ color: '#782B19' }}>{editing !== null ? 'تعديل كلية' : 'إضافة كلية جديدة'}</h2>
        <div className="row g-2">
          <div className="col-md-3"><input className="form-control" placeholder="اسم الكلية" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="col-md-2"><input className="form-control" placeholder="الرمز (مثل SCI)" dir="ltr" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></div>
          <div className="col-md-5"><input className="form-control" placeholder="وصف مختصر (اختياري)" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></div>
          <div className="col-md-2 d-flex gap-1">
            <button className="aust-btn flex-fill" disabled={saving || !form.name || !form.code} onClick={save}>{saving ? '…' : editing !== null ? 'حفظ' : 'إضافة'}</button>
            {editing !== null && <button className="aust-btn-outline" onClick={() => { setEditing(null); setForm(empty) }}>إلغاء</button>}
          </div>
        </div>
      </div>

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>#</th><th>الكلية</th><th>الرمز</th><th>الوصف</th><th></th></tr></thead>
          <tbody>
            {faculties.map((f) => (
              <tr key={f.id}>
                <td>#{f.id}</td>
                <td><strong>{f.name}</strong></td>
                <td dir="ltr">{f.code}</td>
                <td className="small text-muted">{f.description ?? '—'}</td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => { setEditing(f.id); setForm({ name: f.name, code: f.code, description: f.description ?? '' }) }}>تعديل</button>
                  <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(f)}>حذف</button>
                </td>
              </tr>
            ))}
            {faculties.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد كليات</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
