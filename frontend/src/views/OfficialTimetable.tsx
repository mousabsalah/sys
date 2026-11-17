import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { type Call } from '../api'
import type { SheetConfig } from '../types/sheet'
import '../official-sheet.css'

type OfficialRow = {
  day_of_week: string
  start_time: string
  end_time: string
  status: string
  course_code: string
  course_name: string
  faculty_name: string | null
  hall_name: string | null
}

type OfficialData = {
  ok: boolean
  group: { id: number; track: string; student_count: number; batch_name: string; program_name: string | null; department_name: string | null }
  semester: string | null
  institution: { university: string; university_en: string; college: string; college_en: string; college_code: string }
  sheet: SheetConfig
  rows: OfficialRow[]
  issued_at: string
}

const DAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'] as const

/** بناء عنوان الورقة من النمط — {prefix} {track} {batch} {count} {program} */
function renderTitle(
  template: string,
  S: SheetConfig,
  data: { group: { track: string; batch_name: string; student_count: number; program_name: string | null } },
) {
  const out = template
    .replaceAll('{prefix}', S.title_prefix)
    .replaceAll('{track}', data.group.track)
    .replaceAll('{batch}', data.group.batch_name)
    .replaceAll('{count}', String(data.group.student_count))
    .replaceAll('{program}', data.group.program_name ?? '')
  return out.replace(/\s{2,}/g, ' ').trim()
}

export function OfficialTimetableSheet({
  call,
  groupId,
  onClose,
  overrideConfig,
}: {
  call: Call
  groupId: number
  onClose: () => void
  overrideConfig?: SheetConfig
}) {
  const [data, setData] = useState<OfficialData | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => { call<OfficialData>(`/api/council/timetables-official?group_id=${groupId}`).then(setData).catch((e) => setErr((e as Error).message)) }, [groupId, call])

  const S: SheetConfig | null = overrideConfig ?? (data ? data.sheet : null)

  // Periods: استخدم الأول من إعدادات المستخدم — أو يمكن توسيعها مستقبلًا حسب مدّة الدراسة
  const periods = useMemo(() => {
    if (!S) return []
    const out: Array<{ label: string; start: string; end: string }> = []
    for (let i = 0; i < S.column_count; i++) {
      out.push({ label: S.period_labels[i] ?? `الفترة ${i + 1}`, start: (S.period_times[i] ?? ['07:00', '09:00'])[0], end: (S.period_times[i] ?? ['07:00', '09:00'])[1] })
    }
    return out
  }, [S])

  const cells = useMemo(() => {
    const map = new Map<string, Array<{ course: string; lecturer: string; hall: string; status: string }>>()
    if (!data) return map
    for (const r of data.rows) {
      const list = map.get(r.day_of_week) ?? []
      list.push({ course: r.course_name, lecturer: r.faculty_name ?? '—', hall: r.hall_name ?? '—', status: r.status })
      map.set(r.day_of_week, list)
    }
    return map
  }, [data])

  const cellAt = (day: string, periodIndex: number) => (cells.get(day) ?? [])[periodIndex]

  const sheet = (
    <div className="as-sheet" id="official-sheet-root" role="dialog" aria-label="الورقة الرسمية للجدول">
      {/* شريط الأدوات — لا يطبع */}
      <div className="as-actions">
        <button onClick={() => window.print()}>🖨️ طباعة / حفظ PDF</button>
        <button className="secondary" onClick={onClose}>إغلاق المعاينة</button>
      </div>

      {err && <div className="alert alert-danger m-3">{err}</div>}
      {!data && !err && <div className="text-center p-5 text-muted">جارٍ تجهيز الورقة الرسمية…</div>}

      {S && data && (
        <div
          className="as-document"
          style={{
            direction: S.direction,
            fontFamily: S.font_family,
            padding: `${S.page_margin_mm}mm ${S.page_margin_side_mm}mm`,
          }}
        >
          {/* الترويسة — يمكن إخفاؤها، الشعاران قابلان للرفع أو الافتراضية */}
          {S.university_enabled && (
            <header className="as-masthead" style={{ background: S.masthead_bg, borderBottomColor: S.masthead_border }}>
              <div className="as-college">
                {S.college_logo_url && <img src={S.college_logo_url} alt="شعار الكلية" className="as-masthead-small-logo" onError={(e) => { e.currentTarget.style.visibility = 'hidden' }} />}
                <div className="as-college-name">
                  <div className="ar">{S.college_name_ar}</div>
                  <div className="en">{S.college_name_en}</div>
                </div>
              </div>
              <div className="as-university-crest">
                <img src={S.university_logo_url ?? '/logo-aust.jpg'} alt={S.university_name_ar} className="as-masthead-logo" onError={(e) => { e.currentTarget.style.visibility = 'hidden' }} />
              </div>
              <div className="as-university">
                <div className="as-university-name">
                  <div className="ar">{S.university_name_ar}</div>
                  <div className="en">{S.university_name_en}</div>
                </div>
              </div>
            </header>
          )}

          {/* اللافتة — النص يُبنى من نمط العنوان ({prefix} {track} {batch} {count} {program}) */}
          {S.title_enabled && (
            <h1 className="as-title" style={{
              fontSize: `${S.title_font_size}px`, fontWeight: S.title_weight,
              background: S.title_bg, color: S.title_color, borderColor: S.title_border,
              padding: `${S.title_padding}px 20px`,
            }}>
              {renderTitle(S.title_template, S, data)}
            </h1>
          )}

          {/* الجدول */}
          <table className="as-table" aria-label="جدول المحاضرات الأسبوعي" style={{ borderColor: S.table_border, borderWidth: `${S.table_border_width}px` }}>
            <thead>
              <tr>
                <th className="as-day-head" style={{ background: S.day_bg, color: S.header_color, fontSize: `${S.day_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px` }}>اليوم<span className="as-period-time" style={{ color: S.header_color }}>الفترة</span></th>
                {periods.map((p, i) => (
                  <th key={i} style={{ background: S.header_bg, color: S.header_color, fontSize: `${S.header_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px` }}>{p.label}<span className="as-period-time" style={{ color: S.header_color }} dir="ltr">{p.start} — {p.end}</span></th>
                ))}
              </tr>
            </thead>
            <tbody>
              {DAYS.map((day) => (
                <tr key={day}>
                  <th className="as-day-label" scope="row" style={{ background: S.day_bg, fontSize: `${S.day_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px` }}>{day}</th>
                  {periods.map((_p, pi) => {
                    const slot = cellAt(day, pi)
                    return slot ? (
                      <td key={pi} className={`as-cell${slot.status === 'cancelled' ? ' as-cell-cancelled' : ''}`}
                        style={{ background: slot.status === 'cancelled' ? S.cell_empty_bg : S.cell_bg, height: `${S.cell_height}px`, fontSize: `${S.cell_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, color: slot.status === 'cancelled' ? S.cancelled_color : undefined }}>
                        <span className="course">{slot.course}</span>
                        <span className="lecturer">{slot.lecturer}</span>
                        <span className="hall">القاعة ({slot.hall})</span>
                      </td>
                    ) : (
                      <td key={pi} className="as-cell as-cell-empty" style={{ background: S.cell_empty_bg, height: `${S.cell_height}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px` }} aria-label="فراغ" />
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>

          {/* ملاحظة */}
          {S.note_enabled && (
            <div className="as-note" style={{ fontSize: `${S.note_font_size}px`, fontWeight: S.note_weight }}>• يبدأ العمل بالجدول اعتباراً {data.issued_at}</div>
          )}

          {/* التوقيعات */}
          {S.signatures_enabled && (
            <div className="as-signatures">
              <div className="as-sign-block">
                <div className="as-seal" aria-hidden="true" style={{ fontSize: `${S.signature_font_size - 2}px` }}>
                  {S.signature_one_inside_1}
                  <small>{S.signature_one_inside_2}</small>
                  <small>{S.signature_one_inside_3}</small>
                </div>
                <div className="as-sign-line" style={{ borderTopColor: S.table_border }} />
                <div className="as-sign-role" style={{ fontSize: `${S.signature_font_size}px` }}>{S.signature_one_label}</div>
              </div>
              <div className="as-sign-block">
                <div className="as-seal" aria-hidden="true" style={{ fontSize: `${S.signature_font_size - 2}px` }}>
                  {S.signature_two_inside_1}
                  <small>{S.signature_two_inside_2}</small>
                  <small>{S.signature_two_inside_3}</small>
                </div>
                <div className="as-sign-line" style={{ borderTopColor: S.table_border }} />
                <div className="as-sign-role" style={{ fontSize: `${S.signature_font_size}px` }}>{S.signature_two_label}</div>
              </div>
            </div>
          )}

          {/* شريط الاتصال */}
          {S.contact_enabled && (
            <footer className="as-contact" style={{ background: S.contact_bg, color: S.contact_color, fontSize: `${S.contact_font_size}px` }} dir="rtl">
              <span className="as-contact-left" style={{ color: S.contact_color }}>{S.contact_brand}</span>
              <span className="as-contact-right">
                <a href={`https://${S.contact_url}`} style={{ color: S.contact_color, textDecoration: 'none' }}>{S.contact_url}</a>
                <span aria-hidden="true">|</span>
                <a href={`mailto:${S.contact_email}`} style={{ color: S.contact_color, textDecoration: 'none' }}>{S.contact_email}</a>
                <span aria-hidden="true">|</span>
                <a href={`tel:${S.contact_phone1}`} dir="ltr" style={{ color: S.contact_color, textDecoration: 'none' }}>{S.contact_phone1}</a>
                <span aria-hidden="true">|</span>
                <a href={`tel:${S.contact_phone2}`} dir="ltr" style={{ color: S.contact_color, textDecoration: 'none' }}>{S.contact_phone2}</a>
              </span>
            </footer>
          )}
        </div>
      )}
    </div>
  )

  return createPortal(sheet, document.body)
}
