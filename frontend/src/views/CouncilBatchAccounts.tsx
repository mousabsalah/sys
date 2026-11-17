import { useEffect, useState } from 'react'
import { type Call } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type Account = { id: number; username: string; is_active: boolean; last_login_at: string | null }
type GroupChip = { id: number; track: string; student_count: number }
type BatchRow = {
  id: number
  name: string
  student_count: number
  program_id: number
  program: string | null
  department: string | null
  department_id: number
  groups: GroupChip[]
  account: Account | null
}
type Master = { batches: BatchRow[]; departments: { id: number; name: string }[]; programs: { id: number; name: string }[] }

export default function CouncilBatchAccountsView({ call }: { call: Call }) {
  const [rows, setRows] = useState<BatchRow[]>([])
  const [departments, setDepartments] = useState<Master['departments']>([])
  const [programs, setPrograms] = useState<Master['programs']>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [tempPassword, setTempPassword] = useState<{ username: string; password: string } | null>(null)

  // نموذج «دفعة جديدة»
  const [showCreate, setShowCreate] = useState(false)
  const [form, setForm] = useState({ name: '', student_count: '40', دبلوم: true, بكلاريوس: true, program_id: '', department_id: '' })
  const [newProgram, setNewProgram] = useState('')
  const [creatingProgram, setCreatingProgram] = useState(false)
  const [saving, setSaving] = useState(false)

  // نموذج «تعديل الدفعة» (اسم + سعة فقط — الشعب تُحدَّد عند الإنشاء)
  const [editing, setEditing] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({ name: '', student_count: '' })

  // نموذج «إنشاء حساب» لكل دفعة
  const [formFor, setFormFor] = useState<number | null>(null)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')

  async function load() {
    setLoading(true)
    try {
      const d = await call<Master>('/api/council/batch-accounts')
      setRows(d.batches ?? [])
      setDepartments(d.departments ?? [])
      setPrograms(d.programs ?? [])
      if (!form.department_id && d.departments?.length) setForm((f) => ({ ...f, department_id: String(d.departments[0].id) }))
      if (!form.program_id && d.programs?.length) setForm((f) => ({ ...f, program_id: String(d.programs[0].id) }))
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [reloadKey, call])

  async function createProgram() {
    const name = newProgram.trim()
    if (!name) return
    setCreatingProgram(true); setErr(null)
    try {
      const d = await call<{ ok: boolean; program: { id: number; name: string } }>('/api/council/programs', 'POST', { name })
      setPrograms((ps) => [...ps, d.program])
      setForm((f) => ({ ...f, program_id: String(d.program.id) }))
      setNewProgram('')
      setMsg('أُضيف البرنامج «' + name + '» ✓')
    } catch (e) { setErr((e as Error).message) } finally { setCreatingProgram(false) }
  }

  async function createBatch() {
    const total = Number(form.student_count)
    const tracks = [form.دبلوم && 'دبلوم', form.بكلاريوس && 'بكلاريوس'].filter(Boolean) as string[]
    if (!form.name.trim()) { setErr('اسم الدفعة إجباري'); return }
    if (!Number.isInteger(total) || total < 1) { setErr('عدد الطلبة يجب أن يكون رقمًا موجبًا'); return }
    if (tracks.length === 0) { setErr('اختر مسارًا واحدًا على الأقل (دبلوم و/أو بكلاريوس)'); return }
    setSaving(true); setErr(null); setMsg(null)
    try {
      const payload = {
        name: form.name.trim(),
        student_count: total,
        program_id: Number(form.program_id),
        department_id: form.department_id ? Number(form.department_id) : null,
        tracks,
      }
      await call('/api/council/batches', 'POST', payload)
      setMsg(`أُنشئت «${payload.name}» بشعبتيها ✓`)
      setShowCreate(false)
      setForm((f) => ({ ...f, name: '', student_count: '40' }))
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function saveEdit() {
    const total = Number(editForm.student_count)
    if (editing === null || !editForm.name.trim() || !Number.isInteger(total) || total < 1) { setErr('اسم الدفعة إجباري وعدد الطلبة رقم موجب'); return }
    setSaving(true); setErr(null)
    try {
      await call(`/api/council/batches/${editing}`, 'PUT', { name: editForm.name.trim(), student_count: total })
      setMsg('تم تحديث الدفعة ✓')
      setEditing(null)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function deleteBatch(row: BatchRow) {
    if (!window.confirm(`حذف «${row.name}» وشعبه؟ «مرتبطة» بأي جدول/امتحان/حساب/خطة يمنع الحذف.`)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/council/batches/${row.id}`, 'DELETE')
      setMsg('تم حذف الدفعة')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function createAccount(batch: BatchRow) {
    if (!username.trim()) { setErr('اسم الدخول إجباري'); return }
    setSaving(true); setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; account?: { username: string; temporary_password?: string } }>('/api/council/batch-accounts', 'POST', {
        batch_id: batch.id,
        username: username.trim(),
        ...(password ? { password } : {}),
      })
      setMsg(`تم إنشاء حساب «${batch.name}» ✓`)
      if (r?.account?.temporary_password) setTempPassword({ username: r.account.username, password: r.account.temporary_password })
      setFormFor(null); setUsername(''); setPassword('')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function toggleAccount(row: BatchRow) {
    const account = row.account
    if (!account) return
    const action = account.is_active ? 'deactivate' : 'reactivate'
    const confirmMsg = account.is_active
      ? `إيقاف حساب «${account.username}»؟ لن تتمكن الدفعة من الدخول حتى يعيد المجلس التفعيل.`
      : `تفعيل حساب «${account.username}»؟ ستعود الدفعة للدخول فورًا.`
    if (!window.confirm(confirmMsg)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/council/batch-accounts/${account.id}/${action}`, 'POST')
      setMsg(account.is_active ? 'تم إيقاف حساب الدفعة' : 'تم تفعيل حساب الدفعة')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function resetAccountPassword(row: BatchRow) {
    const account = row.account
    if (!account) return
    if (!window.confirm(`توليد كلمة مرور مؤقتة لـ«${account.username}»؟ ستُبطل كل جلسات الدفعة الحالية فورًا.`)) return
    setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; username: string; temporary_password: string }>(`/api/council/batch-accounts/${account.id}/reset-password`, 'POST')
      setTempPassword({ username: r.username, password: r.temporary_password })
      setMsg('تم توليد كلمة مرور مؤقتة')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  if (err && rows.length === 0) return <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />
  if (loading && rows.length === 0) return <SkeletonRows rows={6} height={44} />

  return (
    <>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <h1 className="h3 fw-bold mb-0" style={{ color: '#0A1D3B' }}>إدارة الدفعات</h1>
        <button className="aust-btn" onClick={() => { setShowCreate((s) => !s); setErr(null); setMsg(null) }}>{showCreate ? 'إخفاء النموذج' : '+ دفعة جديدة'}</button>
      </div>
      <p className="small text-muted mb-3">مجلس المدرسين ينشئ الدفعات بشعبها وبرنامجها، ويدير حسابات الدفعات — المدير لا يرى أيًا من ذلك. «حذف الدفعة» ممنوع مع أي استخدام (جداول/امتحانات/حسابات/خطة/فصول).</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      {tempPassword && (
        <div className="alert alert-warning py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap" role="alert">
          <span>
            كلمة المرور المؤقتة للحساب <strong dir="ltr">{tempPassword.username}</strong>:&nbsp;
            <strong dir="ltr">{tempPassword.password}</strong>
            {' '}— تُعرض مرة واحدة فقط، انسخها الآن.
          </span>
          <button className="aust-btn-outline btn-sm" onClick={() => setTempPassword(null)}>فهمت</button>
        </div>
      )}

      {/* ── نموذج دفعة جديدة ── */}
      {showCreate && (
        <div className="aust-panel p-3 mb-3">
          <h2 className="h6 fw-bold mb-2" style={{ color: '#0A1D3B' }}>دفعة جديدة</h2>
          <div className="d-flex gap-2 flex-wrap align-items-center">
            <input className="form-control" style={{ maxWidth: 200 }} placeholder="اسم الدفعة مثل دفعة 2026" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <input className="form-control" style={{ maxWidth: 130 }} type="number" min={1} dir="ltr" placeholder="السعة" value={form.student_count} onChange={(e) => setForm({ ...form, student_count: e.target.value })} />
            <label className="form-check-label d-flex align-items-center gap-1"><input type="checkbox" className="form-check-input m-0" checked={form.دبلوم} onChange={(e) => setForm({ ...form, دبلوم: e.target.checked })} /> دبلوم</label>
            <label className="form-check-label d-flex align-items-center gap-1"><input type="checkbox" className="form-check-input m-0" checked={form.بكلاريوس} onChange={(e) => setForm({ ...form, بكلاريوس: e.target.checked })} /> بكلاريوس</label>
            <select className="form-select" style={{ maxWidth: 220 }} value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })} aria-label="القسم">
              <option value="">— اختر القسم —</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
            <select className="form-select" style={{ maxWidth: 220 }} value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })} aria-label="البرنامج">
              <option value="">— اختر البرنامج —</option>
              {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <input className="form-control" style={{ maxWidth: 200 }} placeholder="برنامج جديد (اتركه اختياريًا للدفعات الفارغة)" value={newProgram} onChange={(e) => setNewProgram(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createProgram() } }} />
            <button className="aust-btn-outline btn-sm" disabled={creatingProgram || !newProgram.trim()} onClick={createProgram}>{creatingProgram ? '…' : '+ برنامج'}</button>
            <button className="aust-btn" disabled={saving || !form.name.trim() || !(form.دبلوم || form.بكلاريوس) || !form.program_id} onClick={createBatch}>{saving ? '…' : 'إنشاء'}</button>
          </div>
          <p className="small text-muted mt-2 mb-0">بالشعبة المحددة — تُنشأ شعبة لكل مسار وتُقسم السعة بالتساوي (الباقي للدبلوم). إذا لم تجد البرنامج المطلوب في القائمة، أكتب اسمه في الحقل يمينها واضغط «+ برنامج».</p>
        </div>
      )}

      {/* ── جدول الدفعات ── */}
      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>الدفعة</th><th>القسم / البرنامج</th><th>الطلبة</th><th>الشعب</th><th>اسم الدخول</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
            {rows.map((b) => (
              <tr key={b.id}>
                <td>
                  {editing === b.id ? (
                    <span className="d-flex gap-1 align-items-center flex-wrap">
                      <input className="form-control form-control-sm" style={{ maxWidth: 170 }} value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} autoFocus />
                      <input className="form-control form-control-sm" style={{ maxWidth: 110 }} type="number" min={1} dir="ltr" value={editForm.student_count} onChange={(e) => setEditForm({ ...editForm, student_count: e.target.value })} />
                      <button className="aust-btn btn-sm" disabled={saving} onClick={saveEdit}>{saving ? '…' : 'حفظ'}</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => setEditing(null)}>إلغاء</button>
                    </span>
                  ) : (
                    <><strong>{b.name}</strong>
                      <button className="btn btn-link btn-sm p-0 ms-1" style={{ color: '#9C4D20' }} onClick={() => { setEditing(b.id); setEditForm({ name: b.name, student_count: String(b.student_count) }) }}>تعديل</button>
                    </>
                  )}
                </td>
                <td className="small">{b.department ?? '—'}<div className="text-muted">{b.program ?? ''}</div></td>
                <td>{b.student_count}</td>
                <td>
                  {b.groups.map((g) => (
                    <span key={g.id} className="badge me-1" style={{ background: '#0A1D3B', color: '#fff' }}>{g.track} × {g.student_count}</span>
                  ))}
                </td>
                <td dir="ltr" className="small">{b.account?.username ?? <span className="text-muted">— لم يُنشأ بعد</span>}</td>
                <td>
                  {b.account
                    ? (b.account.is_active
                        ? <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>مفعل</span>
                        : <span className="badge" style={{ background: '#782B19', color: '#fff' }}>موقوف</span>)
                    : <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>بدون حساب</span>}
                </td>
                <td className="d-flex gap-1 flex-wrap">
                  {b.account ? (
                    <>
                      <button className="aust-btn-outline btn-sm" onClick={() => toggleAccount(b)}>{b.account.is_active ? 'إيقاف' : 'تفعيل'}</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => resetAccountPassword(b)}>كلمة المرور</button>
                    </>
                  ) : formFor === b.id ? (
                    <span className="d-flex gap-1 align-items-center flex-wrap">
                      <input className="form-control form-control-sm" style={{ width: 150 }} dir="ltr" placeholder="اسم الدخول" value={username} onChange={(e) => setUsername(e.target.value)} autoFocus />
                      <input className="form-control form-control-sm" style={{ width: 150 }} dir="ltr" type="password" placeholder="كلمة المرور (اختياري)" value={password} onChange={(e) => setPassword(e.target.value)} />
                      <button className="aust-btn btn-sm" disabled={saving || !username.trim()} onClick={() => createAccount(b)}>{saving ? '…' : 'إنشاء'}</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => { setFormFor(null); setUsername(''); setPassword('') }}>إلغاء</button>
                    </span>
                  ) : (
                    <button className="aust-btn btn-sm" onClick={() => { setFormFor(b.id); setUsername(''); setPassword(''); setErr(null) }}>إنشاء حساب</button>
                  )}
                  <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => deleteBatch(b)}>حذف</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد دفعات — أنشئ أول دفعة من الزر أعلاه</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
