<?php

namespace App\Services\OfficialSheet;

/**
 * OfficialSheetConfig — المصدر الوحيد لإعدادات الورقة الرسمية (CSS-compatible dictionary).
 * تتحكم ضبط الورقة «من أعلى إلى أسفل» لكل خصايصها: الترويسة ← العنوان ← الجدول ← التواريخ ← الأختام ← التذييل،
 * بالإضافة إلى عرض العدد — بدون أي سطر خارج هذا الملف.
 */
final class OfficialSheetConfig
{
    /** الهيكل الكامل الافتراضي للورقة — تسطير بحسب مناطقها من أعلى لأسفل. */
    public static function defaults(): array
    {
        return [
            // ── الترويسة (رأس الورقة) ──
            'university_enabled' => true,
            'university_name_ar' => 'جامعة الشرق للعلوم والتكنولوجيا',
            'university_name_en' => 'Alsharq University of Science and Technology',
            'university_logo_url' => null,        // null = الأصل الداخلي /logo-aust.jpg
            'college_name_ar' => 'كلية علوم الحاسوب',
            'college_name_en' => 'Faculty of Computer Science',
            'college_code' => 'IT',
            'college_logo_url' => null,           // null = لا يوجد شعار كلية (النص يكفي)
            'masthead_bg' => '#ffffff',
            'masthead_border' => '#0a0a0a',

            // ── اللافتة (عنوان الجدول) ──
            'title_enabled' => true,
            'title_prefix' => 'جدول',
            'title_template' => '{prefix} شعبة {track} — دفعة {batch} ({count} طالبًا)',
            'title_font_size' => 20,
            'title_weight' => 800,
            'title_bg' => '#ffffff',
            'title_color' => '#0a0a0a',
            'title_border' => '#0a0a0a',
            'title_padding' => 8,

            // ── الجدول الأسبوعي ──
            'table_border' => '#0a0a0a',
            'table_border_width' => 2.4,
            'table_cell_border_width' => 1.4,
            'header_bg' => '#b8b8b8',
            'header_color' => '#0a0a0a',
            'header_font_size' => 17,
            'day_bg' => '#d8d8d8',
            'day_font_size' => 16,
            'cell_font_size' => 14.5,
            'cell_bg' => '#ffffff',
            'cell_empty_bg' => '#e2e2e2',
            'cell_height' => 82,
            'cancelled_color' => '#555555',
            'column_count' => 3,                  // عدد الفترة (يتطابق مع عدد عناصر periods)
            'period_labels' => ['الفترة الأولى', 'الفترة الثانية', 'الفترة الثالثة'],
            'period_times' => [['07:00', '09:00'], ['10:00', '12:00'], ['12:00', '14:00']],

            // ── ملاحظة الدوام ──
            'note_enabled' => true,
            'note_font_size' => 13,
            'note_weight' => 800,

            // ── التوقيعات (الأختامي) ──
            'signatures_enabled' => true,
            'signature_one_label' => 'مسجل الكلية',
            'signature_one_inside_1' => 'جامعة الشرق',
            'signature_one_inside_2' => 'مسجل الكلية',
            'signature_one_inside_3' => 'ALSHARQ UNIV',
            'signature_two_label' => 'عميد الكلية',
            'signature_two_inside_1' => 'DEAN',
            'signature_two_inside_2' => 'Faculty of Computer Science',
            'signature_two_inside_3' => 'ALSHARQ UNIVERSITY',
            'signature_font_size' => 14,

            // ── شريط الاتصال (التذييل) ──
            'contact_enabled' => true,
            'contact_brand' => 'جامعة الشرق للعلوم والتكنولوجيا',
            'contact_phone1' => '+249154968035',
            'contact_phone2' => '+249915496335',
            'contact_email' => 'Computer@aust.edu.sd',
            'contact_url' => 'www.aust.edu.sd',
            'contact_bg' => '#0A1D3B',
            'contact_color' => '#ffffff',
            'contact_font_size' => 10,

            // ── الورقة (Page setup) ──
            'page_margin_mm' => 7,
            'page_margin_side_mm' => 8,
            'font_family' => '"Noto Kufi Arabic", "Noto Naskh Arabic", "Simplified Arabic", Tahoma, serif',
            'direction' => 'rtl',      // كتابة من اليمين لليسار كما في النموذج الأصلي
        ];
    }

    /** دمج التعديلات مع الافتراضية مع التحقق من مفاتيح معروفة فقط. */
    public static function mergedFrom(array $saved): array
    {
        return array_merge(self::defaults(), array_intersect_key($saved, self::defaults()));
    }

    /** JSON للإدراج في التهجير */
    public static function defaultJson(): string
    {
        return json_encode(self::defaults(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function read(): array
    {
        $row = \DB::table('sheet_settings')->where('key', 'official_sheet')->first();
        if (! $row) {
            return self::defaults();
        }
        $decoded = json_decode((string) $row->value, true);

        return self::mergedFrom(is_array($decoded) ? $decoded : []);
    }
}
