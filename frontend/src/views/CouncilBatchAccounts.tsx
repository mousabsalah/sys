import { useEffect, useState } from 'react'
import useSeqGuard from '../components/useSeqGuard'
import TempPasswordAlert from '../components/TempPasswordAlert'
import { type Call } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'
import BatchCreateForm from './BatchCreateForm'
import BatchRowAccountCell from './BatchRowAccountCell'
import type { BatchRow, CreateFormState, Master } from './CouncilBatchAccountsTypes'

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
  const [form, setForm] = useState<CreateFormState>({ name: '', student_count: '40', دبلوم: true, بكلاريوس: true, program_id: '', department_id: '' })
  const [newProgram, setNewProgram] = useState('')
  const [creatingProgram, setCreatingProgram] = useState(false)
  const [saving, setSaving] = useState(false)

  // نموذج «تعديل الدفعة» (اسم + سعة فقط — الشعب تُحدَّد عند الإنشاء)
  const [editing, setEditing] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({ name: '', student_count: '' })

  const { next, isCurrent } = useSeqGuard()

  async function load() {
    const seq = next()
    setLoading(true)
    try {
      const d = await call<Master>('/api/council/batch-accounts')
      if (!isCurrent(seq)) return
      setRows(d.batches ?? [])
      setDepartments(d.departments ?? [])
      setPrograms(d.programs ?? [])
      if (!form.department_id && d.departments?.length) setForm((f) => ({ ...f, department_id: String(d.departments[0].id) }))
      if (!form.program_id && d.programs?.length) setForm((f) => ({ ...f, program_id: String(d.programs[0].id) }))
      setErr(null)
    } catch (e) {
      if (!isCurrent(seq)) return
      setErr((e as Error).message) } finally { setLoading(false) }
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

  async function createAccount(batch: BatchRow, username: string, password: string): Promise<boolean> {
    setSaving(true); setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; account?: { username: string; temporary_password?: string } }>('/api/council/batch-accounts', 'POST', {
        batch_id: batch.id,
        username,
        ...(password ? { password } : {}),
      })
      setMsg(`تم إنشاء حساب «${batch.name}» ✓`)
      if (r?.account?.temporary_password) setTempPassword({ username: r.account.username, password: r.account.temporary_password })
      await load()
      return true
    } catch (e) { setErr((e as Error).message); return false } finally { setSaving(false) }
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
        <h1 className="h3 fw-bold mb-0 aust-h1">إدارة الدفعات</h1>
        <button className="aust-btn" onClick={() => { setShowCreate((s) => !s); setErr(null); setMsg(null) }}>{showCreate ? 'إخفاء النموذج' : '+ دفعة جديدة'}</button>
      </div>
      <p className="small text-muted mb-3">مجلس المدرسين ينشئ الدفعات بشعبها وبرنامجها، ويدير حسابات الدفعات — المدير لا يرى أيًا من ذلك. «حذف الدفعة» ممنوع مع أي استخدام (جداول/امتحانات/حسابات/خطة/فصول).</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      {tempPassword && (
        <TempPasswordAlert username={tempPassword.username} password={tempPassword.password} onClose={() => setTempPassword(null)} />
      )}

      {/* ── نموذج دفعة جديدة ── */}
      {showCreate && (
        <BatchCreateForm departments={departments} programs={programs} form={form} onChange={setForm} newProgram={newProgram} onNewProgramChange={setNewProgram} creatingProgram={creatingProgram} onCreateProgram={createProgram} saving={saving} onSubmit={createBatch} />
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
                    <span key={g.id} className="badge me-1 aust-badge-navy">{g.track} × {g.student_count}</span>
                  ))}
                </td>
                <td dir="ltr" className="small">{b.account?.username ?? <span className="text-muted">— لم يُنشأ بعد</span>}</td>
                <td>
                  {b.account
                    ? (b.account.is_active
                        ? <span className="badge aust-badge-ok">مفعل</span>
                        : <span className="badge aust-badge-danger">موقوف</span>)
                    : <span className="badge aust-badge-neutral">بدون حساب</span>}
                </td>
                <td className="d-flex gap-1 flex-wrap">
                  <BatchRowAccountCell batch={b} saving={saving} onToggle={toggleAccount} onResetPassword={resetAccountPassword} onCreateAccount={createAccount} onDelete={deleteBatch} />
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
