// إعدادات «الورقة الرسمية» — مرآة للخلفية (App\Services\OfficialSheet\OfficialSheetConfig)
export type SheetConfig = {
  // الترويسة
  university_enabled: boolean
  university_name_ar: string
  university_name_en: string
  university_logo_url: string | null
  college_name_ar: string
  college_name_en: string
  college_code: string
  college_logo_url: string | null
  masthead_bg: string
  masthead_border: string

  // اللافتة
  title_enabled: boolean
  title_prefix: string
  title_template: string
  title_font_size: number
  title_weight: number
  title_bg: string
  title_color: string
  title_border: string
  title_padding: number

  // الجدول
  table_border: string
  table_border_width: number
  table_cell_border_width: number
  header_bg: string
  header_color: string
  header_font_size: number
  day_bg: string
  day_font_size: number
  cell_font_size: number
  cell_bg: string
  cell_empty_bg: string
  cell_height: number
  cancelled_color: string
  column_count: number
  period_labels: string[]
  period_times: [string, string][]

  // ملاحظة
  note_enabled: boolean
  note_font_size: number
  note_weight: number

  // التوقيعات
  signatures_enabled: boolean
  signature_one_label: string
  signature_one_inside_1: string
  signature_one_inside_2: string
  signature_one_inside_3: string
  signature_two_label: string
  signature_two_inside_1: string
  signature_two_inside_2: string
  signature_two_inside_3: string
  signature_font_size: number

  // شريط الاتصال
  contact_enabled: boolean
  contact_brand: string
  contact_phone1: string
  contact_phone2: string
  contact_email: string
  contact_url: string
  contact_bg: string
  contact_color: string
  contact_font_size: number

  // الورقة
  page_margin_mm: number
  page_margin_side_mm: number
  font_family: string
  direction: 'rtl' | 'ltr'
}
