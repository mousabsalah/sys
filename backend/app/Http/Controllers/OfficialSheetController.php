<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\OfficialSheet\OfficialSheetConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OfficialSheetController — «محرر الورقة الرسمية» لدور «مجلس المدرسين» حصريًا.
 * كل خصايص الورقة من أعلى لأسفل قابلة للتعديل: الترويسة ← العنوان ← الجدول ← الأختام ← التذييل ← الورقة.
 */
class OfficialSheetController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** قائمة مسارات الارتباط (string|null) — لا غيرها يقبل أو يُحفظ */
    private const STRING_KEYS = [
        'university_name_ar', 'university_name_en', 'college_name_ar', 'college_name_en', 'college_code',
        'title_prefix', 'title_template',
        'signature_one_label', 'signature_one_inside_1', 'signature_one_inside_2', 'signature_one_inside_3',
        'signature_two_label', 'signature_two_inside_1', 'signature_two_inside_2', 'signature_two_inside_3',
        'contact_brand', 'contact_phone1', 'contact_phone2', 'contact_email', 'contact_url',
        'cancelled_color', 'header_bg', 'header_color', 'day_bg', 'cell_bg', 'cell_empty_bg',
        'masthead_bg', 'masthead_border', 'university_logo_url', 'college_logo_url',
        'title_bg', 'title_color', 'title_border', 'table_border', 'contact_bg', 'contact_color',
        'font_family', 'direction',
    ];

    private const BOOL_KEYS = [
        'university_enabled', 'title_enabled', 'note_enabled',
        'signatures_enabled', 'contact_enabled',
    ];

    private const INT_KEYS = [
        'title_font_size', 'header_font_size', 'day_font_size',
        'note_font_size', 'contact_font_size', 'signature_font_size',
        'title_padding', 'cell_height', 'column_count',
        'page_margin_mm', 'page_margin_side_mm', 'title_weight', 'note_weight',
    ];

    private const FLOAT_KEYS = ['table_border_width', 'table_cell_border_width', 'cell_font_size'];

    private const CLAMP = [
        'title_font_size' => [10, 40], 'header_font_size' => [10, 30], 'day_font_size' => [10, 30],
        'cell_font_size' => [8, 24], 'note_font_size' => [8, 20], 'contact_font_size' => [6, 20],
        'signature_font_size' => [8, 22], 'title_padding' => [2, 30], 'cell_height' => [40, 160],
        'column_count' => [1, 6], 'page_margin_mm' => [3, 20], 'page_margin_side_mm' => [3, 20],
        'table_border_width' => [0.5, 6], 'table_cell_border_width' => [0.3, 4],
    ];

    // GET /api/council/sheet-settings — الإعدادات الحالية (مدموجة مع الافتراضيات)
    public function show(): JsonResponse
    {
        return response()->json(['settings' => OfficialSheetConfig::read()]);
    }

    // PUT /api/council/sheet-settings — حفظ (لا تُقبل مفاتيح غير معروفة)
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        if ($input === []) {
            throw ValidationException::withMessages(['payload' => ['لا توجد تعديلات للحفظ.']]);
        }

        $clean = [];

        foreach (self::STRING_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key];
                if ($value !== null && ! is_string($value)) {
                    throw ValidationException::withMessages([$key => ['حقل نصي مطلوب.']]);
                }
                $value = $value === null ? null : mb_substr((string) $value, 0, 255);
                $clean[$key] = $value;
            }
        }

        foreach (self::BOOL_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                if (! is_bool($input[$key])) {
                    throw ValidationException::withMessages([$key => ['قيمة منطقية مطلوبة.']]);
                }
                $clean[$key] = $input[$key];
            }
        }

        foreach (self::INT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $num = $input[$key];
                if (! is_int($num) && ! (is_string($num) && is_numeric($num))) {
                    throw ValidationException::withMessages([$key => ['رقم صحيح مطلوب.']]);
                }
                $clean[$key] = (int) $num;
            }
        }

        foreach (self::FLOAT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $num = $input[$key];
                if (! is_int($num) && ! is_float($num) && ! (is_string($num) && is_numeric($num))) {
                    throw ValidationException::withMessages([$key => ['رقم مطلوب.']]);
                }
                $clean[$key] = (float) $num;
            }
        }

        // مصفوفات الفترة — مطابقة لعدد الفترة المحددة
        $defaults = OfficialSheetConfig::defaults();
        $columnCount = (int) ($clean['column_count'] ?? $defaults['column_count']);
        if (isset($input['column_count']) && ((int) $input['column_count'] < 1 || (int) $input['column_count'] > 6)) {
            throw ValidationException::withMessages(['column_count' => ['عدد الفترة بين 1 و6.']]);
        }

        if (isset($input['period_labels'])) {
            $labels = $input['period_labels'];
            if (! is_array($labels) || count($labels) !== $columnCount) {
                throw ValidationException::withMessages(['period_labels' => ['قائمة أسماء الفترات يجب أن تطابق عدد الفترة.']]);
            }
            foreach ($labels as $i => $label) {
                if (! is_string($label) || mb_strlen(trim($label)) === 0 || mb_strlen($label) > 60) {
                    throw ValidationException::withMessages(['period_labels' => ['تسمية الفترة #'.($i + 1).' غير صالحة.']]);
                }
            }
            $clean['period_labels'] = array_values(array_map('strval', $labels));
        }

        if (isset($input['period_times'])) {
            $times = $input['period_times'];
            if (! is_array($times) || count($times) !== $columnCount) {
                throw ValidationException::withMessages(['period_times' => ['قائمة أوقات الفترات يجب أن تطابق عدد الفترة.']]);
            }
            $checked = [];
            foreach ($times as $i => $pair) {
                if (! is_array($pair) || count($pair) !== 2) {
                    throw ValidationException::withMessages(['period_times' => ['الفتة #'.($i + 1).': تحتاج [بداية، نهاية].']]);
                }
                foreach ([0, 1] as $idx) {
                    $t = $pair[$idx];
                    if (! is_string($t) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                        throw ValidationException::withMessages(['period_times' => ['الفتة #'.($i + 1).': وقت غير صالح — استخدم HH:MM']]);
                    }
                }
                $checked[] = [(string) $pair[0], (string) $pair[1]];
            }
            $clean['period_times'] = $checked;
        }

        // clamp لأحجام وارتفاعات عدة
        foreach (self::CLAMP as $k => [$lo, $hi]) {
            if (isset($clean[$k])) {
                $clean[$k] = max($lo, min($hi, (float) $clean[$k]));
                if (in_array($k, self::INT_KEYS, true)) {
                    $clean[$k] = (int) $clean[$k];
                }
            }
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['payload' => ['لا توجد حقول معروفة لتعديلها.']]);
        }

        $merged = OfficialSheetConfig::saveMerged($clean);
        $this->audit->log($request, 'sheet_settings.save', 'sheet_settings', 1, ['keys' => array_keys($clean)]);

        return response()->json(['ok' => true, 'settings' => $merged]);
    }

    // POST /api/council/sheet-settings/logo — رفع شعار (university|college)
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'slot' => ['required', 'string', 'in:university,college'],
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
        ], [
            'slot.required' => 'اختر مكان الشعار (الجامعة أم الكلية).',
            'logo.required' => 'الصورة إجبارية.',
            'logo.mimes' => 'الصيغ المقبولة: PNG, JPG, WEBP, SVG',
            'logo.max' => 'الحد الأقصى للحجم 5MB.',
        ]);

        $file = $request->file('logo');
        $ext = strtolower($file->getClientOriginalExtension());
        $name = 'sheet_'.$request->input('slot').'_'.Str::random(8).'.'.$ext;

        $file->storeAs('sheet', $name, 'public');
        $url = '/storage/sheet/'.$name;

        // حذف الشعار القديم فقط لو كان مرفوعًا لـ sheet (لا تمس الأصل /logo-aust.jpg)
        $key = $request->input('slot') === 'university' ? 'university_logo_url' : 'college_logo_url';
        $current = OfficialSheetConfig::read();
        $old = $current[$key] ?? null;
        if ($old && str_starts_with((string) $old, '/storage/sheet/')) {
            $oldPath = storage_path('app/public/'.ltrim(substr((string) $old, strlen('/storage/')), '/'));
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        OfficialSheetConfig::saveMerged([$key => $url]);
        $this->audit->log($request, 'sheet_settings.logo', 'sheet_settings', 1, ['slot' => $request->input('slot'), 'url' => $url]);

        return response()->json(['ok' => true, 'url' => $url]);
    }

    // POST /api/council/sheet-settings/reset — إرجاع الإعدادات الافتراضية
    public function reset(Request $request): JsonResponse
    {
        DB::table('sheet_settings')->where('key', 'official_sheet')->update([
            'value' => OfficialSheetConfig::defaultJson(),
            'updated_at' => now(),
        ]);
        $this->audit->log($request, 'sheet_settings.reset', 'sheet_settings', 1, []);

        return response()->json(['ok' => true, 'settings' => OfficialSheetConfig::defaults()]);
    }
}
