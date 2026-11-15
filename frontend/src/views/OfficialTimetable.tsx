import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useAuthedApi } from '../api'
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
  rows: OfficialRow[]
  issued_at: string
}

type Period = { index: number; label: string; start: string; end: string }

const DAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'] as const

const PERIOD_DEFAULTS: Period[] = [
  { index: 1, label: 'الفترة الأولى', start: '07:00', end: '09:00' },
  { index: 2, label: 'الفترة الثانية', start: '10:00', end: '12:00' },
  { index: 3, label: 'الفترة الثالثة', start: '12:00', end: '14:00' },
]

function pad(n: number) { return String(n).padStart(2, '0') }
function fmtTime(t: string) { const [h, m] = t.slice(0, 5).split(':').map(Number); return `${pad(h)}:${pad(m)}` }

export function OfficialTimetableSheet({ call, groupId, onClose }: { call: ReturnType<typeof useAuthedApi>; groupId: number; onClose: () => void }) {
  const [data, setData] = useState<OfficialData | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => { call<OfficialData>(`/api/council/timetables-official?group_id=${groupId}`).then(setData).catch((e) => setErr((e as Error).message)) }, [groupId, call])

  const periods: Period[] = (() => {
    if (!data || data.rows.length === 0) return PERIOD_DEFAULTS
    const uniq = new Map<string, { start: string; end: string }>()
    for (const r of data.rows) {
      const key = fmtTime(r.start_time)
      if (!uniq.has(key)) uniq.set(key, { start: fmtTime(r.start_time), end: fmtTime(r.end_time) })
    }
    const sorted = [...uniq.entries()].sort((a, b) => a[1].start.localeCompare(b[1].start)).slice(0, 6)
    return sorted.map(([, v], i) => ({ index: i + 1, label: `الفترة ${['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة'][i]}`, start: v.start, end: v.end }))
  })()

  const cells = (() => {
    const map = new Map<string, { start: string; end?: string; course: string; lecturer: string; hall: string; status: string }[]>()
    if (!data) return map
    for (const r of data.rows) {
      const list = map.get(r.day_of_week) ?? []
      list.push({ start: fmtTime(r.start_time), end: fmtTime(r.end_time), course: r.course_name, lecturer: r.faculty_name ?? '—', hall: r.hall_name ?? '—', status: r.status })
      map.set(r.day_of_week, list)
    }
    return map
  })()

  const cellAt = (day: string, periodStart: string) => (cells.get(day) ?? []).find((c) => c.start === periodStart)

  const inst = data?.institution
  const title = data ? `جدول بكالوريوس ${data.group.program_name ?? 'تقنية المعلومات'} الدفعة (${data.group.student_count})` : 'الجدول'
  const startNote = data ? `يبدأ العمل بالجدول اعتباراً ${data.issued_at}` : ''

  const sheet = (
    <div id="official-sheet-root" className="as-sheet" role="dialog" aria-label="الورقة الرسمية للجدول">
      {/* شريط الأدوات — لا يطبع */}
      <div className="as-actions">
        <button onClick={() => window.print()}>🖨️ طباعة / حفظ PDF</button>
        <button className="secondary" onClick={onClose}>إغلاق المعاينة</button>
      </div>

      {err && <div className="alert alert-danger m-3">{err}</div>}
      {!data && !err && <div className="text-center p-5 text-muted">جارٍ تجهيز الورقة الرسمية…</div>}

      {data && (
        <div className="as-document">
          {/* الترويسة — الجامعة يمين والكلية يسار (نفس الأصل) */}
          <header className="as-masthead">
            <div className="as-college">
              <img src="/logo-aust.jpg" alt="شعار الجامعة" className="as-masthead-small-logo" />
              <div className="as-college-name">
                <div className="ar">{inst?.college ?? 'كلية علوم الحاسوب'}</div>
                <div className="en">{inst?.college_en ?? 'Faculty of Computer Science'}</div>
              </div>
            </div>
            <div className="as-university-crest">
              <img src="/logo-aust.jpg" alt="AUST" className="as-masthead-logo" />
            </div>
            <div className="as-university">
              <div className="as-university-name">
                <div className="ar">{inst?.university ?? 'جامعة الشرق للعلوم والتكنولوجيا'}</div>
                <div className="en">{inst?.university_en ?? 'Alsharq University of Science and Technology'}</div>
              </div>
              <img src="/logo-aust.jpg" alt="AUST" className="as-masthead-small-logo" style={{ borderRadius: 8, background: '#fff' }} />
            </div>
          </header>

          {/* اللافتة */}
          <h1 className="as-title">{title}</h1>

          {/* الجدول */}
          <table className="as-table" aria-label="جدول المحاضرات الأسبوعي">
            <thead>
              <tr>
                <th className="as-day-head" style={{ width: 92 }}>
                  اليوم
                  <span className="as-period-time">الفترة</span>
                </th>
                {periods.map((p) => (
                  <th key={p.index}>
                    {p.label}
                    <span className="as-period-time" dir="ltr">{p.start} — {p.end}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {DAYS.map((day) => (
                <tr key={day}>
                  <th className="as-day-label" scope="row">{day}</th>
                  {periods.map((p) => {
                    const slot = cellAt(day, p.start)
                    return slot ? (
                      <td key={p.index} className={`as-cell${slot.status === 'cancelled' ? ' as-cell-cancelled' : ''}`}>
                        <span className="course">{slot.course}</span>
                        <span className="lecturer">{slot.lecturer}</span>
                        <span className="hall" dir="rtl">القاعة ({slot.hall})</span>
                      </td>
                    ) : (
                      <td key={p.index} className="as-cell as-cell-empty" aria-label="فراغ" />
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>

          {/* ملاحظة بدء العمل */}
          <div className="as-note">• {startNote}</div>

          {/* التوقيعات والأختام */}
          <div className="as-signatures">
            <div className="as-sign-block">
              <div className="as-seal" aria-hidden="true">
                جامعة الشرق
                <small>{(inst?.college ?? 'كلية علوم الحاسوب').slice(0, 14)}</small>
                <small>ALSHARQ UNIV</small>
              </div>
              <div className="as-sign-line" />
              <div className="as-sign-role">مسجل الكلية</div>
            </div>
            <div className="as-sign-block">
              <div className="as-seal" aria-hidden="true">
                DEAN
                <small>Faculty of Computer Science</small>
                <small>ALSHARQ UNIVERSITY OF</small>
              </div>
              <div className="as-sign-line" />
              <div className="as-sign-role">عميد الكلية</div>
            </div>
          </div>

          {/* شريط الاتصال */}
          <footer className="as-contact" dir="rtl">
            <span className="as-contact-left">جامعة الشرق للعلوم والتكنولوجيا</span>
            <span className="as-contact-right">
              <a href="http://www.aust.edu.sd">www.aust.edu.sd</a>
              <span aria-hidden="true">|</span>
              <a href="mailto:Computer@aust.edu.sd">Computer@aust.edu.sd</a>
              <span aria-hidden="true">|</span>
              <a href="tel:+249154968035" dir="ltr">+249154968035</a>
              <span aria-hidden="true">|</span>
              <a href="tel:+249915496335" dir="ltr">+249915496335</a>
            </span>
          </footer>
        </div>
      )}
    </div>
  )

  return createPortal(sheet, document.body)
}
