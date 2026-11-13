import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type Account = { id: number; username: string; is_active: boolean; last_login_at: string | null }
type BatchRow = { id: number; name: string; student_count: number; department: string | null; account: Account | null }

export default function CouncilBatchAccountsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [rows, setRows] = useState<BatchRow[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [tempPassword, setTempPassword] = useState<{ username: string; password: string } | null>(null)
  const [formFor, setFormFor] = useState<number | null>(null)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [saving, setSaving] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const d = await call<{ batches: BatchRow[] }>('/api/council/batch-accounts')
      setRows(d.batches ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [reloadKey, call])

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
      if (r?.account?.temporary_password) {
        setTempPassword({ username: r.account.username, password: r.account.temporary_password })
      }
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
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>حسابات الدفعات</h1>
      <p className="small text-muted mb-3">مجلس المدرسين ينشئ يدير حسابات دخول الدفعات — المدير لا يراها ولا يلمسها. كل دفعة لها حساب واحد.</p>
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

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>الدفعة</th><th>القسم</th><th>الطلبة</th><th>اسم الدخول</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
            {rows.map((b) => (
              <tr key={b.id}>
                <td><strong>{b.name}</strong></td>
                <td className="small">{b.department ?? '—'}</td>
                <td>{b.student_count}</td>
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
                      <input className="form-control form-control-sm" style={{ width: 170 }} dir="ltr" placeholder="اسم الدخول" value={username} onChange={(e) => setUsername(e.target.value)} autoFocus />
                      <input className="form-control form-control-sm" style={{ width: 170 }} dir="ltr" type="password" placeholder="كلمة المرور (اختياري)" value={password} onChange={(e) => setPassword(e.target.value)} />
                      <button className="aust-btn btn-sm" disabled={saving || !username.trim()} onClick={() => createAccount(b)}>{saving ? '…' : 'إنشاء'}</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => { setFormFor(null); setUsername(''); setPassword('') }}>إلغاء</button>
                    </span>
                  ) : (
                    <button className="aust-btn btn-sm" onClick={() => { setFormFor(b.id); setUsername(''); setPassword(''); setErr(null) }}>إنشاء حساب</button>
                  )}
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد دفعات — أضف الدفعات من إدارة الفصول/البيانات الرئيسية أولًا</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
