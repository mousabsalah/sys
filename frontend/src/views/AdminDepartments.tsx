import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type Faculty = { id: number; name: string; code: string }
type Department = { id: number; faculty_id: number; name: string; code: string }
type Master = { faculties: Faculty[]; departments: Department[] }

const empty = { faculty_id: '', name: '', code: '' }

export default function AdminDepartmentsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [form, setForm] = useState(empty)
  const [editing, setEditing] = useState<number | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  async function refresh() {
    try {
      const data = await call<Master>('/api/admin/master-data')
      setFaculties(data.faculties ?? [])
      setDepartments(data.departments ?? [])
    } catch (e) { setErr((e as Error).message) }
    finally { setLoading(false) }
  }
  useEffect(() => { refresh() }, [])

  const facultyName = (id: number) => faculties.find((f) => f.id === id)?.name ?? `#${id}`

  async function save() {
    setSaving(true); setErr(null); setMsg(null)
    const payload: Record<string, unknown> = {
      faculty_id: Number(form.faculty_id),
      name: form.name,
      code: form.code,
    }
    try {
      if (editing !== null) {
        await call(`/api/admin/departments/${editing}`, 'PUT', payload)
        setMsg('تم تحديث القسم ✓')
      } else {
        await call('/api/admin/departments', 'POST', payload)
        setMsg('تم إضافة القسم ✓')
      }
      setForm(empty); setEditing(null)
      refresh()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function remove(d: Department) {
    if (!window.confirm(`حذف قسم «${d.name}»؟ الحذف ممنوع إذا كانت به دفعات.`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/departments/${d.id}`, 'DELETE')
      setMsg('تم حذف القسم'); refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{ color: '#0A1D3B' }}>إدارة الأقسام</h1>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="aust-panel p-3 mb-3">
        <h2 className="h5 mb-2" style={{ color: '#782B19' }}>{editing !== null ? 'تعديل قسم' : 'إضافة قسم جديد'}</h2>
        <div className="row g-2">
          <div className="col-md-3">
            <select className="form-select" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: e.target.value })}>
              <option value="">— اختر الكلية —</option>
              {faculties.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </select>
          </div>
          <div className="col-md-3"><input className="form-control" placeholder="اسم القسم" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="col-md-2"><input className="form-control" placeholder="الرمز (CS)" dir="ltr" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></div>
          <div className="col-md-2 d-flex gap-1">
            <button className="aust-btn flex-fill" disabled={saving || !form.faculty_id || !form.name || !form.code} onClick={save}>{saving ? '…' : editing !== null ? 'حفظ' : 'إضافة'}</button>
            {editing !== null && <button className="aust-btn-outline" onClick={() => { setEditing(null); setForm(empty) }}>إلغاء</button>}
          </div>
        </div>
      </div>

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>#</th><th>القسم</th><th>الرمز</th><th>الكلية</th><th></th></tr></thead>
          <tbody>
            {departments.map((d) => (
              <tr key={d.id}>
                <td>#{d.id}</td>
                <td><strong>{d.name}</strong></td>
                <td dir="ltr">{d.code}</td>
                <td>{facultyName(d.faculty_id)}</td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => { setEditing(d.id); setForm({ faculty_id: String(d.faculty_id), name: d.name, code: d.code }) }}>تعديل</button>
                  <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(d)}>حذف</button>
                </td>
              </tr>
            ))}
            {loading && <tr><td colSpan={5} className="text-muted">جارٍ التحميل…</td></tr>}
            {!loading && departments.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد أقسام</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
