import { useEffect, useState } from 'react'
import { BASE, useAuthedApi } from '../api'

type Faculty = { id: number; name: string; code: string }
type Department = { id: number; faculty_id: number; name: string; code: string }
type Batch = { id: number; name: string; student_count: number; department_id: number; program_id: number }
type Master = { faculties: Faculty[]; departments: Department[]; batches: Batch[] }

type Semester = {
  id: number
  faculty_id: number
  department_id: number
  batch_id: number
  academic_term: string
  start_date: string | null
  end_date: string | null
  archived_at: string | null
  faculty?: string
  department?: string
  batch?: string
  student_count?: number
}

type BackupRow = { file: string; size_kb: number; created_at: string }

const empty = { faculty_id: '', department_id: '', batch_id: '', academic_term: '', start_date: '', end_date: '' }

export default function AdminSemestersView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [batches, setBatches] = useState<Batch[]>([])
  const [semesters, setSemesters] = useState<Semester[]>([])
  const [backups, setBackups] = useState<BackupRow[]>([])
  const [form, setForm] = useState(empty)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [backupBusy, setBackupBusy] = useState(false)

  async function refresh() {
    try {
      const [master, semi, bak] = await Promise.all([
        call<Master>('/api/admin/master-data'),
        call<{ semesters: Semester[] }>('/api/admin/semesters'),
        call<{ backups: BackupRow[] }>('/api/admin/backup/list'),
      ])
      setFaculties(master.faculties ?? [])
      setDepartments(master.departments ?? [])
      setBatches(master.batches ?? [])
      setSemesters(semi.semesters ?? [])
      setBackups(bak.backups ?? [])
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { refresh() }, [])

  const deptOptions = departments.filter((d) => !form.faculty_id || d.faculty_id === Number(form.faculty_id))
  const batchOptions = batches.filter((b) => !form.department_id || b.department_id === Number(form.department_id))

  async function create() {
    setSaving(true); setErr(null); setMsg(null)
    try {
      await call('/api/admin/semesters', 'POST', {
        faculty_id: Number(form.faculty_id),
        department_id: Number(form.department_id),
        batch_id: Number(form.batch_id),
        academic_term: form.academic_term,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
      })
      setMsg('تم إنشاء الفصل الدراسي ✓')
      setForm(empty); refresh()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function archive(s: Semester) {
    setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; backup: string | null }>(`/api/admin/semesters/${s.id}/archive`, 'POST')
      setMsg(`تمت أرشفة الفصل ✓ — نسخة احتياطية: ${r.backup ?? 'تعذر إنشاؤها'}`)
      refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  async function unarchive(s: Semester) {
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/semesters/${s.id}/unarchive`, 'POST')
      setMsg('تم إلغاء الأرشفة'); refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  async function remove(s: Semester) {
    if (!window.confirm(`حذف فصل «${s.academic_term}»؟ الحذف ممنوع إذا كان مرتبطًا بجداول أو امتحانات.`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/semesters/${s.id}`, 'DELETE')
      setMsg('تم حذف الفصل'); refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  async function createBackup() {
    setBackupBusy(true); setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; file: string; size_kb: number }>('/api/admin/backup/create', 'POST')
      setMsg(`تم إنشاء النسخة الاحتياطية ✓ (${r.file} — ${r.size_kb} KB)`)
      refresh()
    } catch (e) { setErr((e as Error).message) } finally { setBackupBusy(false) }
  }

  async function downloadBackup(file: string) {
    const token = sessionStorage.getItem('aust_token')
    const res = await fetch(`${BASE}/api/admin/backup/download/${encodeURIComponent(file)}`, {
      headers: { Authorization: `Bearer ${token ?? ''}` },
    })
    if (!res.ok) { setErr('تعذر تحميل الملف'); return }
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = file; a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{ color: '#0A1D3B' }}>الفصول الدراسية (المسارات والتواريخ)</h1>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="aust-panel p-3 mb-3">
        <h2 className="h5 mb-2" style={{ color: '#782B19' }}>إنشاء فصل دراسي جديد (لكل قسم/دفعة على حدة)</h2>
        <div className="row g-2">
          <div className="col-md-2">
            <select className="form-select" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: e.target.value, department_id: '', batch_id: '' })}>
              <option value="">— الكلية —</option>
              {faculties.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </select>
          </div>
          <div className="col-md-2">
            <select className="form-select" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value, batch_id: '' })}>
              <option value="">— القسم —</option>
              {deptOptions.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div className="col-md-2">
            <select className="form-select" value={form.batch_id} onChange={(e) => setForm({ ...form, batch_id: e.target.value })}>
              <option value="">— الدفعة —</option>
              {batchOptions.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div className="col-md-2"><input className="form-control" placeholder="المسمى (2026/2)" dir="ltr" value={form.academic_term} onChange={(e) => setForm({ ...form, academic_term: e.target.value })} /></div>
          <div className="col-md-1"><input className="form-control" type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} aria-label="بداية الفصل" /></div>
          <div className="col-md-1"><input className="form-control" type="date" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} aria-label="نهاية الفصل" /></div>
          <div className="col-md-1 d-flex"><button className="aust-btn flex-fill" disabled={saving || !form.faculty_id || !form.department_id || !form.batch_id || !form.academic_term} onClick={create}>{saving ? '…' : '+'}</button></div>
        </div>
      </div>

      <div className="table-responsive mb-4">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>الفصل</th><th>الكلية</th><th>القسم</th><th>الدفعة</th><th>التواريخ</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
            {semesters.map((s) => (
              <tr key={s.id} style={s.archived_at ? { opacity: 0.65 } : undefined}>
                <td dir="ltr"><strong>{s.academic_term}</strong></td>
                <td>{s.faculty ?? `#${s.faculty_id}`}</td>
                <td>{s.department ?? `#${s.department_id}`}</td>
                <td>{s.batch ?? `#${s.batch_id}`}{s.student_count != null ? <span className="text-muted small"> ({s.student_count})</span> : null}</td>
                <td className="small" dir="ltr">{s.start_date ?? '—'} ← {s.end_date ?? '—'}</td>
                <td>{s.archived_at
                  ? <span className="badge" style={{ background: '#555', color: '#fff' }}>مؤرشف</span>
                  : <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>جارٍ</span>}
                </td>
                <td className="d-flex gap-1">
                  {!s.archived_at
                    ? <button className="aust-btn-outline btn-sm" onClick={() => archive(s)}>أرشفة</button>
                    : <button className="aust-btn-outline btn-sm" onClick={() => unarchive(s)}>إلغاء أرشفة</button>}
                  <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(s)}>حذف</button>
                </td>
              </tr>
            ))}
            {semesters.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد فصول</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="aust-panel p-3">
        <div className="d-flex align-items-center justify-content-between mb-2">
          <h2 className="h5 mb-0" style={{ color: '#782B19' }}>النسخ الاحتياطي لقاعدة البيانات</h2>
          <button className="aust-btn" disabled={backupBusy} onClick={createBackup}>{backupBusy ? 'جارٍ النسخ…' : 'إنشاء نسخة الآن'}</button>
        </div>
        <p className="small text-muted mb-2">نسخة كاملة (.sql) تُنشأ تلقائيًا عند أرشفة أي فصل. الاحتفاظ بآخر 10 نسخ.</p>
        <ul className="mb-0 small">
          {backups.map((b) => (
            <li key={b.file} className="d-flex align-items-center justify-content-between gap-2 py-1" style={{ borderBottom: '1px solid #f0ece3' }}>
              <span dir="ltr"><code>{b.file}</code> <span className="text-muted">({b.size_kb} KB)</span></span>
              <button className="aust-btn-outline btn-sm" onClick={() => downloadBackup(b.file)}>تحميل</button>
            </li>
          ))}
          {backups.length === 0 && <li className="text-muted">لا توجد نسخ محفوظة بعد</li>}
        </ul>
      </div>
    </>
  )
}
