import { useState } from 'react'

/**
 * TempPasswordAlert — تنبيه كلمة المرور المؤقتة الجديد بعد الإنشاء/إعادة التعيين.
 * تُعرض مرة واحدة فقط وتُنسخ بضغطة. موحّد عبر شاشات المجلس والأدمن.
 */
export default function TempPasswordAlert({ username, password, onClose }: {
  username: string
  password: string
  onClose: () => void
}) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(password)
      setCopied(true)
    } catch {
      // الحافظة غير متاحة — الحدود مكتوبة في نفس الشاشة
    }
  }

  return (
    <div className="alert alert-warning py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap" role="alert" dir="rtl">
      <span className="small">
        كلمة المرور المؤقتة للحساب <strong dir="ltr">{username}</strong>: <code dir="ltr" className="fs-5">{password}</code>
        {' '}— تُعرض مرة واحدة فقط، انسخها الآن.
      </span>
      <div className="d-flex gap-2 flex-shrink-0">
        <button className="aust-btn-outline btn-sm" onClick={copy}>{copied ? 'تم النسخ ✓' : 'نسخ'}</button>
        <button className="aust-btn-outline btn-sm" onClick={onClose}>فهمت</button>
      </div>
    </div>
  )
}
