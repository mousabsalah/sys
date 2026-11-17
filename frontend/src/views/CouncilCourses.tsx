import { useEffect, useState } from 'react'
import { type Call } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type CourseRow = {
  id: number
  code: string
  name: string
  credit_hours: number
  required_equipment: string | null
  timetables_count: number
  exams_count: number
  lecturers_count: number
  plans_count: number
}

const emptyForm = { code: '', name: '', credit_hours: '3', required_equipment: '' }

export default function CouncilCoursesView({ call }: { call: Call }) {
  const [rows, setRows] = useState<CourseRow[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const d = await call<{ courses: CourseRow[] }>('/api/council/courses')
      setRows(d.courses ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [reloadKey, call])

  function startEdit(c: CourseRow) {
    setForm({ code: c.code, name: c.name, credit_hours: String(c.credit_hours), required_equipment: c.required_equipment ?? '' })
    setEditing(c.id); setShowForm(true); setErr(null); setMsg(null)
  }

  function startAdd() {
    setForm(emptyForm); setEditing(null); setShowForm(true); setErr(null); setMsg(null)
  }

  async function save() {
    const credit = Number(form.credit_hours)
    if (!form.code.trim() || !form.name.trim() || !Number.isInteger(credit) || credit < 1 || credit > 30) {
      setErr('تحقق من الحقول: الكود والاسم إجباريان وعدد الساعات من 1 إلى 30')
      return
    }
    setSaving(true); setErr(null); setMsg(null)
    const payload = { code: form.code.trim(), name: form.name.trim(), credit_hours: credit, required_equipment: form.required_equipment.trim() || null }
    try {
      if (editing !== null) {
        await call(`/api/council/courses/${editing}`, 'PUT', payload)
        setMsg('تم تحديث المقرر ✓')
      } else {
        await call('/api/council/courses', 'POST', payload)
        setMsg('أُضيف المقرر ✓')
      }
      setShowForm(false); setEditing(null); setForm(emptyForm)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function remove(c: CourseRow) {
    if (!window.confirm(`حذف المقرر «${c.code} — ${c.name}»؟ ممنوع مع أي استخدام (جداول/امتحانات/مدرسون/خطة).`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/council/courses/${c.id}`, 'DELETE')
      setMsg('تم حذف المقرر')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  if (err && rows.length === 0) return <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />
  if (loading && rows.length === 0) return <SkeletonRows rows={6} height={44} />

  return (
    <>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <h1 className="h3 fw-bold mb-0 aust-h1">إدارة المقررات</h1>
        <button className="aust-btn" onClick={startAdd}>+ مقرر جديد</button>
      </div>
      <p className="small text-muted mb-3">مجلس المدرسين يضيف/يعدّل/يحذف المقررات — المدير يقرأها فقط لعرض قوائم «الخطط الدراسية».</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}

      {showForm && (
        <div className="aust-panel p-3 mb-3">
          <h2 className="h6 fw-bold mb-2 aust-h2">{editing !== null ? 'تعديل مقرر' : 'مقرر جديد'}</h2>
          <div className="d-flex gap-2 flex-wrap">
            <input className="form-control" style={{ maxWidth: 150 }} dir="ltr" placeholder="الكود مثل CS101" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            <input className="form-control" style={{ maxWidth: 320 }} placeholder="اسم المقرر" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <input className="form-control" style={{ maxWidth: 90 }} type="number" min={1} max={30} dir="ltr" placeholder="ساعات" value={form.credit_hours} onChange={(e) => setForm({ ...form, credit_hours: e.target.value })} />
            <input className="form-control" style={{ maxWidth: 220 }} dir="rtl" placeholder="تجهيزات مطلوبة (اختياري)" value={form.required_equipment} onChange={(e) => setForm({ ...form, required_equipment: e.target.value })} />
            <button className="aust-btn" disabled={saving || !form.code.trim() || !form.name.trim()} onClick={save}>{saving ? '…' : editing !== null ? 'حفظ' : 'إضافة'}</button>
            <button className="aust-btn-outline" onClick={() => { setShowForm(false); setEditing(null) }}>إلغاء</button>
          </div>
        </div>
      )}

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>الكود</th><th>اسم المقرر</th><th>ساعات</th><th>التجهيزات</th><th>الاستخدام</th><th></th></tr></thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.id}>
                <td dir="ltr" className="fw-bold">{c.code}</td>
                <td>{c.name}</td>
                <td>{c.credit_hours}</td>
                <td className="small text-muted">{c.required_equipment ?? '—'}</td>
                <td className="small">
                  {c.timetables_count + c.exams_count + c.lecturers_count + c.plans_count === 0
                    ? <span className="text-muted">غير مستخدم</span>
                    : <span>{c.timetables_count} محاضرة · {c.exams_count} امتحان · {c.lecturers_count} مدرس · {c.plans_count} خطة</span>}
                </td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => startEdit(c)}>تعديل</button>
                  <button className="aust-btn-danger-outline btn-sm" onClick={() => remove(c)}>حذف</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد مقررات بعد — أضف أول مقرر من الزر أعلاه</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
