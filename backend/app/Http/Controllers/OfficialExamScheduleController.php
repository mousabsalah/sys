<?php

namespace App\Http\Controllers;

use App\Services\Academic\ArabicDay;
use App\Services\OfficialSheet\OfficialSheetConfig;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OfficialExamScheduleController — «جدول الامتحانات الرسمي» للطباعة (Siegel AUST).
 * نفس الترويسة/اللافتة/الخواتم/التذييل لـ«الورقة الرسمية للجدول الدراسي»،
 * لكن الجدول نفسه عمودي — صفوف الامتحانات على تواريخ حقيقية بترتيب التاريخ.
 */
class OfficialExamScheduleController extends Controller
{
    // GET /api/council/exam-schedules/official?group_id=N — نفس الشعبة بصيغة الطباعة الرسمية
    public function show(Request $request): JsonResponse
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

        // آخر فصل جارٍ لم يُؤرشف (إن وُجد) — نفس حمولة الورقة الدراسية
        $term = DB::table('semesters')
            ->where('batch_id', $group->batch_id)
            ->whereNull('archived_at')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->value('academic_term');

        $rows = DB::table('exam_sessions')
            ->join('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'exam_sessions.hall_id')
            ->where('exam_sessions.group_id', $groupId)
            ->whereNotIn('exam_sessions.status', ['pending', 'archived'])
            ->where(function ($q) {
                $q->whereNull('exam_sessions.semester_id')
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('semesters')
                        ->whereColumn('semesters.id', 'exam_sessions.semester_id')
                        ->whereNull('semesters.archived_at'));
            })
            ->select(
                'exam_sessions.exam_date',
                'exam_sessions.exam_type',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'exam_sessions.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
            )
            ->orderBy('exam_sessions.exam_date')
            ->orderBy('exam_sessions.start_time')
            ->get();

        $rowsPayload = [];
        foreach ($rows as $r) {
            $rowsPayload[] = [
                'exam_date' => (string) $r->exam_date,
                'day' => ArabicDay::of(Carbon::parse((string) $r->exam_date)),
                'exam_type' => (string) $r->exam_type,
                'start_time' => substr((string) $r->start_time, 0, 8),
                'end_time' => substr((string) $r->end_time, 0, 8),
                'status' => (string) $r->status,
                'course_code' => (string) $r->course_code,
                'course_name' => (string) $r->course_name,
                'hall_name' => $r->hall_name !== null ? (string) $r->hall_name : null,
            ];
        }

        $sheet = OfficialSheetConfig::read();
        // اللافتة تُخصَّص للامتحانات — نفس الآلية النمطية: {prefix} يُعوَّض تلقائيًا في renderTitle
        $sheet['title_prefix'] = 'جدول امتحانات نهاية الفصل';
        $sheet['title_template'] = '{prefix} — دفعة {batch} — شعبة {track} ({count} طالبًا)';

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
            'rows' => $rowsPayload,
            'issued_at' => Carbon::now()->format('Y/m/d').'م',
        ]);
    }
}
