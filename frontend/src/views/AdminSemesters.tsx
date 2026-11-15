import { useEffect, useState } from 'react'
import { BASE, useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

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

export default function AdminSemestersView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [semesters, setSemesters] = useState<Semester[]>([])
  const [backups, setBackups] = useState<BackupRow[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [backupBusy, setBackupBusy] = useState(false)

  async function refresh() {
    try {
      const [semi, bak] = await Promise.all([
        call<{ semesters: Semester[] }>('/api/admin/semesters'),
        call<{ backups: BackupRow[] }>('/api/admin/backup/list'),
      ])
      setSemesters(semi.semesters ?? [])
      setBackups(bak.backups ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { refresh() }, [])

  async function createBackup() {
    setBackupBusy(true); setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; file: string; size_kb: number }>('/api/admin/backup/create', 'POST')
      setMsg(`تم إنشاء النسخة الاحتياطية ✓ (${r.file} — ${r.size_kb} KB)`)
      await refresh()
    } catch (e) { setErr((e as Error).message) } finally { setBackupBusy(false) }
  }

  async function downloadBackup(file: string) {
    try {
      const token = sessionStorage.getItem('aust_token')
      const res = await fetch(`${BASE}/api/admin/backup/download/${encodeURIComponent(file)}`, {
        headers: { Authorization: `Bearer ${token ?? ''}` },
      })
      if (!res.ok) { setErr(`تعذر تحميل الملف (${res.status})`); return }
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = file; a.click()
      URL.revokeObjectURL(url)
    } catch (e) { setErr(`تعذر تحميل النسخة: ${(e as Error).message}`) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>الفصول الدراسية — قراءة فقط</h1>
      <p className="small text-muted mb-3">كل عمليات الإدارة (فتح السنة، إنشاء/تعديل/أرشفة/حذف الفصول) تتم عبر <strong>مجلس المدرسين</strong> ← «بداية السنة الدراسية». هذه الشاشة للمشاهدة فقط.</p>
      <div className="alert alert-info py-2 small" role="note">هذه شاشة مشاهدة — أي تعديل مطلوب يتم من «بداية السنة الدراسية» بحساب مجلس المدرسين.</div>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={refresh} />}

      <div className="table-responsive mb-4">
        {semesters.length === 0 && !err && <SkeletonRows rows={4} height={38} />}
        {semesters.length > 0 && (
          <table className="table table-sm aust-panel align-middle">
            <thead className="table-light"><tr><th>الفصل</th><th>الكلية</th><th>القسم</th><th>الدفعة</th><th>التواريخ</th><th>الحالة</th></tr></thead>
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
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="aust-panel p-3">
        <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
          <h2 className="h5 mb-0" style={{ color: '#0A1D3B' }}>النسخ الاحتياطية الأخيرة</h2>
          <button className="aust-btn" disabled={backupBusy} onClick={createBackup}>{backupBusy ? 'جارٍ الإنشاء…' : '+ نسخة احتياطية الآن'}</button>
        </div>
        <ul className="list-group">
          {backups.map((b) => (
            <li key={b.file} className="list-group-item d-flex align-items-center justify-content-between gap-2">
              <span dir="ltr" className="small">{b.file}</span>
              <span className="d-flex align-items-center gap-2">
                <span className="small text-muted">{b.size_kb.toFixed(1)} KB</span>
                <button className="aust-btn-outline btn-sm" onClick={() => downloadBackup(b.file)}>تحميل</button>
              </span>
            </li>
          ))}
          {backups.length === 0 && <li className="list-group-item text-muted">لا توجد نسخ احتياطية بعد — أي أرشفة لفصل عند المجلس تُنشئ تلقائيًا</li>}
        </ul>
      </div>
    </>
  )
}
