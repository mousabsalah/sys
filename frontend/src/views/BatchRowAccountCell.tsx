import { useState } from 'react'
import type { BatchRow } from './CouncilBatchAccountsTypes'

/**
 * BatchRowAccountCell — خلية «حساب الدفعة» في صف الجدول:
 * إيقاف/تفعيل + كلمة مرور، أو نموذج «إنشاء حساب» الداخلي، أو زر الإنشاء، وأخيرًا زر الحذف المحروس.
 */
export default function BatchRowAccountCell({ batch, saving, onToggle, onResetPassword, onCreateAccount, onDelete }: {
  batch: BatchRow
  saving: boolean
  onToggle: (b: BatchRow) => void
  onResetPassword: (b: BatchRow) => void
  onCreateAccount: (b: BatchRow, username: string, password: string) => Promise<boolean>
  onDelete: (b: BatchRow) => void
}) {
  const [showForm, setShowForm] = useState(false)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')

  const account = batch.account

  async function submit() {
    if (!username.trim()) return
    const ok = await onCreateAccount(batch, username.trim(), password)
    if (!ok) return // إبقاء المدخلات — رسالة الخطأ تظهر في البانر
    setShowForm(false)
    setUsername('')
    setPassword('')
  }

  return (
    <>
      {account ? (
        <>
          <button className="aust-btn-outline btn-sm" onClick={() => onToggle(batch)}>{account.is_active ? 'إيقاف' : 'تفعيل'}</button>
          <button className="aust-btn-outline btn-sm" onClick={() => onResetPassword(batch)}>كلمة المرور</button>
        </>
      ) : showForm ? (
        <span className="d-flex gap-1 align-items-center flex-wrap">
          <input className="form-control form-control-sm" style={{ width: 150 }} dir="ltr" placeholder="اسم الدخول" value={username} onChange={(e) => setUsername(e.target.value)} autoFocus />
          <input className="form-control form-control-sm" style={{ width: 150 }} dir="ltr" type="password" placeholder="كلمة المرور (اختياري)" value={password} onChange={(e) => setPassword(e.target.value)} />
          <button className="aust-btn btn-sm" disabled={saving || !username.trim()} onClick={submit}>{saving ? '…' : 'إنشاء'}</button>
          <button className="aust-btn-outline btn-sm" onClick={() => { setShowForm(false); setUsername(''); setPassword('') }}>إلغاء</button>
        </span>
      ) : (
        <button className="aust-btn btn-sm" onClick={() => { setShowForm(true); setUsername(''); setPassword('') }}>إنشاء حساب</button>
      )}
      <button className="aust-btn-danger-outline btn-sm" onClick={() => onDelete(batch)}>حذف</button>
    </>
  )
}
