import type { Lecturer } from '../views/CouncilLecturersTypes'

/**
 * LecturersTable — جدول المدرسين مع حالة الحساب، وأزرار الإيقاف/التفعيل وكلمة المرور والحذف.
 */
export default function LecturersTable({ lecturers, selectedId, onPick, onEdit, onToggleAccount, onResetPassword, onRemove }: {
  lecturers: Lecturer[]
  selectedId: number | null
  onPick: (l: Lecturer) => void
  onEdit: (l: Lecturer) => void
  onToggleAccount: (l: Lecturer) => void
  onResetPassword: (l: Lecturer) => void
  onRemove: (l: Lecturer) => void
}) {
  return (
    <div className="table-responsive">
      <table className="table table-sm aust-panel align-middle">
        <thead className="table-light"><tr><th>#</th><th>المدرس</th><th>الدرجة</th><th>الكليات</th><th>مقررات</th><th></th></tr></thead>
        <tbody>
          {lecturers.map((l) => (
            <tr key={l.id} className={selectedId === l.id ? 'table-active' : undefined}>
              <td>#{l.id}</td>
              <td>
                <strong>{l.full_name}</strong>
                <div className="small text-muted" dir="ltr">{l.email}</div>
                <div className="small">
                  {l.username
                    ? <span dir="ltr" className="text-muted">{l.username}</span>
                    : <span className="text-muted">بدون حساب</span>}
                  {' · '}
                  {l.account_active === true && <span className="badge aust-badge-ok">مفعل</span>}
                  {l.account_active === false && <span className="badge aust-badge-danger">موقوف</span>}
                </div>
              </td>
              <td>{l.degree ?? '—'}</td>
              <td className="small">{l.faculties.map((f) => f.name).join('، ') || '—'}</td>
              <td>{l.courses_count}</td>
              <td className="d-flex gap-1 flex-wrap">
                <button className="aust-btn-outline btn-sm" onClick={() => onPick(l)}>مقرراته</button>
                <button className="aust-btn-outline btn-sm" onClick={() => onEdit(l)}>تعديل</button>
                {l.username && <button className="aust-btn-outline btn-sm" onClick={() => onToggleAccount(l)}>{l.account_active ? 'إيقاف الحساب' : 'تفعيل الحساب'}</button>}
                {l.username && <button className="aust-btn-outline btn-sm" onClick={() => onResetPassword(l)}>كلمة المرور</button>}
                <button className="aust-btn-danger-outline btn-sm" onClick={() => onRemove(l)}>حذف</button>
              </td>
            </tr>
          ))}
          {lecturers.length === 0 && <tr><td colSpan={6} className="text-muted">لا يوجد مدرسون — أضف أول مدرس من الزر أعلاه</td></tr>}
        </tbody>
      </table>
    </div>
  )
}
