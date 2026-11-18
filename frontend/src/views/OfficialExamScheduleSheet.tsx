import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { type Call } from '../api'
import type { SheetConfig } from '../types/sheet'
import '../official-sheet.css'

type OfficialRow = {
  exam_date: string
  day: string
  exam_type: string
  start_time: string
  end_time: string
  status: string
  course_code: string
  course_name: string
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

export function OfficialExamScheduleSheet({
  call,
  groupId,
  onClose,
}: {
  call: Call
  groupId: number
  onClose: () => void
}) {
  const [data, setData] = useState<OfficialData | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => { call<OfficialData>(`/api/council/exam-schedules/official?group_id=${groupId}`).then(setData).catch((e) => setErr((e as Error).message)) }, [groupId, call])

  const S: SheetConfig | null = data ? data.sheet : null

  // مدى تواريخ الامتحانات للعرض وحسب احترازه بالضبط من الصفوف المعتمدة (ما عدا الملغية)
  const dateRange = useMemo(() => {
    if (!data) return null
    const dates = data.rows.filter((r) => r.status !== 'cancelled').map((r) => r.exam_date).sort()
    if (dates.length === 0) return null
    return { from: dates[0], to: dates[dates.length - 1] }
  }, [data])

  const sheet = (
    <div className="as-sheet" id="official-exam-sheet-root" role="dialog" aria-label="جدول الامتحانات الرسمي">
      {/* شريط الأدوات — لا يطبع */}
      <div className="as-actions">
        <button onClick={() => window.print()}>🖨️ طباعة / حفظ PDF</button>
        <button className="secondary" onClick={onClose}>إغلاق المعاينة</button>
      </div>

      {err && <div className="alert alert-danger m-3">{err}</div>}
      {!data && !err && <div className="text-center p-5 text-muted">جارٍ تجهيز جدول الامتحانات الرسمي…</div>}

      {S && data && (
        <div
          className="as-document"
          style={{
            direction: S.direction,
            fontFamily: S.font_family,
            padding: `${S.page_margin_mm}mm ${S.page_margin_side_mm}mm`,
          }}
        >
          {/* الترويسة */}
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

          {/* اللافتة */}
          {S.title_enabled && (
            <h1 className="as-title" style={{
              fontSize: `${S.title_font_size}px`, fontWeight: S.title_weight,
              background: S.title_bg, color: S.title_color, borderColor: S.title_border,
              padding: `${S.title_padding}px 20px`,
            }}>
              {renderTitle(S.title_template, S, data)}
            </h1>
          )}

          {/* جدول الامتحانات — صف لكل امتحان مرتب بالتاريخ */}
          <table className="as-table" aria-label="جدول الامتحانات" style={{ borderColor: S.table_border, borderWidth: `${S.table_border_width}px` }}>
            <thead>
              <tr>
                {(['التاريخ', 'اليوم', 'الوقت', 'النوع', 'المقرر', 'القاعة'] as const).map((h) => (
                  <th key={h}
                    style={{ background: S.header_bg, color: S.header_color, fontSize: `${S.header_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center' }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r, i) => {
                const cancelled = r.status === 'cancelled'
                return (
                  <tr key={i}>
                    <td dir="ltr" className="as-day-label" style={{ background: S.day_bg, fontSize: `${S.day_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center', color: cancelled ? S.cancelled_color : undefined }}>{r.exam_date}</td>
                    <td className="as-day-label" style={{ background: S.day_bg, fontSize: `${S.day_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center', color: cancelled ? S.cancelled_color : undefined }}>{r.day}</td>
                    <td dir="ltr" style={{ fontSize: `${S.cell_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center', color: cancelled ? S.cancelled_color : undefined }}>
                      {r.start_time.slice(0, 5)} — {r.end_time.slice(0, 5)}
                    </td>
                    <td style={{ fontSize: `${S.cell_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center', color: cancelled ? S.cancelled_color : undefined }}>{r.exam_type}</td>
                    <td className="as-cell" style={{ fontSize: `${S.cell_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 10px', color: cancelled ? S.cancelled_color : undefined }}>
                      <span className="course">{r.course_name}</span>
                      <span className="lecturer" style={{ direction: 'ltr' }}>{r.course_code}</span>
                    </td>
                    <td style={{ fontSize: `${S.cell_font_size}px`, borderColor: S.table_border, borderWidth: `${S.table_cell_border_width}px`, padding: '10px 8px', textAlign: 'center', color: cancelled ? S.cancelled_color : undefined }}>{r.hall_name ?? '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>

          {/* ملاحظة */}
          {S.note_enabled && (
            <div className="as-note" style={{ fontSize: `${S.note_font_size}px`, fontWeight: S.note_weight }}>
              {dateRange ? (
                <>• تُعقد الامتحانات من <span dir="ltr">{dateRange.from}</span> وحتى <span dir="ltr">{dateRange.to}</span> · تبدأ المحاضرات النظامية بعد نهاية الامتحانات</>
              ) : (
                <>• يبدأ العمل بالامتحانات اعتباراً {data.issued_at}</>
              )}
            </div>
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
