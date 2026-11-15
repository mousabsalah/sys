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

export const DEFAULT_SHEET_SETTINGS: SheetConfig = {
  university_enabled: true,
  university_name_ar: 'جامعة الشرق للعلوم والتكنولوجيا',
  university_name_en: 'Alsharq University of Science and Technology',
  university_logo_url: null,
  college_name_ar: 'كلية علوم الحاسوب',
  college_name_en: 'Faculty of Computer Science',
  college_code: 'IT',
  college_logo_url: null,
  masthead_bg: '#ffffff',
  masthead_border: '#0a0a0a',

  title_enabled: true,
  title_prefix: 'جدول',
  title_template: '{prefix} شعبة {track} — دفعة {batch} ({count} طالبًا)',
  title_font_size: 20,
  title_weight: 800,
  title_bg: '#ffffff',
  title_color: '#0a0a0a',
  title_border: '#0a0a0a',
  title_padding: 8,

  table_border: '#0a0a0a',
  table_border_width: 2.4,
  table_cell_border_width: 1.4,
  header_bg: '#b8b8b8',
  header_color: '#0a0a0a',
  header_font_size: 17,
  day_bg: '#d8d8d8',
  day_font_size: 16,
  cell_font_size: 14.5,
  cell_bg: '#ffffff',
  cell_empty_bg: '#e2e2e2',
  cell_height: 82,
  cancelled_color: '#555555',
  column_count: 3,
  period_labels: ['الفترة الأولى', 'الفترة الثانية', 'الفترة الثالثة'],
  period_times: [['07:00', '09:00'], ['10:00', '12:00'], ['12:00', '14:00']],

  note_enabled: true,
  note_font_size: 13,
  note_weight: 800,

  signatures_enabled: true,
  signature_one_label: 'مسجل الكلية',
  signature_one_inside_1: 'جامعة الشرق',
  signature_one_inside_2: 'مسجل الكلية',
  signature_one_inside_3: 'ALSHARQ UNIV',
  signature_two_label: 'عميد الكلية',
  signature_two_inside_1: 'DEAN',
  signature_two_inside_2: 'Faculty of Computer Science',
  signature_two_inside_3: 'ALSHARQ UNIVERSITY',
  signature_font_size: 14,

  contact_enabled: true,
  contact_brand: 'جامعة الشرق للعلوم والتكنولوجيا',
  contact_phone1: '+249154968035',
  contact_phone2: '+249915496335',
  contact_email: 'Computer@aust.edu.sd',
  contact_url: 'www.aust.edu.sd',
  contact_bg: '#101010',
  contact_color: '#ffffff',
  contact_font_size: 10,

  page_margin_mm: 7,
  page_margin_side_mm: 8,
  font_family: '"Noto Kufi Arabic", "Noto Naskh Arabic", "Simplified Arabic", Tahoma, serif',
  direction: 'rtl',
}
