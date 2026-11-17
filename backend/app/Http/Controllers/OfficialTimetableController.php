<?php

namespace App\Http\Controllers;

use App\Services\Academic\ArabicDay;
use App\Services\OfficialSheet\OfficialSheetConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OfficialTimetableController — «ورقة الجدول الرسمي» للطباعة (ختم المجلس + الكلية + الجامعة).
 * الدور المجلس (council) فقط — جدول الشعبة المعتمدة (+ الملغية موثقة) بصيغة الجداول الأصلية للجامعة.
 * المنهج البصري من النموذج الأصلي: ترويسة كلية/جامعة + شعار الكلية يمين + الشعار الرئيسي لوسط + إطار عريض + لافتة عنوان + جدول عريض.
 */
class OfficialTimetableController
{
    // GET /api/council/timetables-official?group_id=N — نفس الشعبة بصيغة الطباعة الرسمية
    public function show(Request $request)
    {
        $input = $request->validate(['group_id' => ['required', 'integer']]);
        $groupId = (int) $input['group_id'];

        $group = DB::table('groups')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->leftJoin('programs', 'programs.id', '=', 'batches.program_id')
            ->leftJoin('departments', 'departments.id', '=', 'batches.department_id')
            ->where('groups.id', $groupId)
            ->first(['groups.id', 'groups.track', 'groups.student_count', 'batches.id AS batch_id', 'batches.name AS batch_name', 'programs.name AS program_name', 'departments.name AS department_name']);

        if (! $group) {
            return response()->json(['ok' => false, 'message_ar' => 'الشعبة غير موجودة.'], 404);
        }

        // آخر فصل جارٍ لم يُؤرشف (إن وُجد) — للعرض في لافتة البرنامج/الفصل
        $term = DB::table('semesters')
            ->where('batch_id', $group->batch_id)
            ->whereNull('archived_at')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->value('academic_term');

        $rows = DB::table('timetables')
            ->join('courses', 'courses.id', '=', 'timetables.course_id')
            ->join('groups', 'groups.id', '=', 'timetables.group_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->where('timetables.group_id', $groupId)
            ->where('timetables.status', '!=', 'pending')
            ->where(function ($q) {
                $q->whereNull('timetables.semester_id')
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('semesters')
                        ->whereColumn('semesters.id', 'timetables.semester_id')
                        ->whereNull('semesters.archived_at'));
            })
            ->select(
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'faculty.full_name AS faculty_name',
                'halls.name AS hall_name',
            )
            ->orderByRaw(ArabicDay::dayOrderSql('timetables.day_of_week'))
            ->orderBy('timetables.start_time')
            ->get();

        $sheet = OfficialSheetConfig::read();

        $college = DB::table('faculties')->orderBy('id')->first(['name', 'code']);

        return response()->json([
            'ok' => true,
            'group' => $group,
            'semester' => $term,
            'institution' => [
                'university' => $sheet['university_name_ar'] ?? (data_get($college, 'name', 'كلية علوم الحاسوب') ?: 'جامعة الشرق للعلوم والتكنولوجيا'),
                'university_en' => $sheet['university_name_en'] ?? 'Alsharq University of Science and Technology',
                'college' => $sheet['college_name_ar'] ?? (data_get($college, 'name', 'كلية علوم الحاسوب') ?: 'كلية علوم الحاسوب'),
                'college_en' => $sheet['college_name_en'] ?? 'Faculty of Computer Science',
                'college_code' => $sheet['college_code'] ?? (data_get($college, 'code', 'IT') ?: 'IT'),
            ],
            'sheet' => $sheet,
            'rows' => $rows,
            'issued_at' => Carbon::now()->format('Y/m/d').'م',
        ]);
    }
}
