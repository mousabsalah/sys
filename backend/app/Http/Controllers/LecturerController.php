<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Group;
use App\Models\SubstituteRequest;
use App\Models\Timetable;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * LecturerController — دور «مدرس»: يرى جدول الشعب التي يدرس فيها (المعتمدة والملغية)،
 * يلغي محاضرته (منطقيًا)، يطلب بديلًا من مدرس آخر بنفس نطاق الشعب — بالتفاوض مباشرة بلا مجلس/مدير.
 */
class LecturerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** uid للحساب ← المدرس؛ 403 عند غيابه/تعذر الربط */
    private function lecturerFor(Request $request): Faculty
    {
        $user = $request->user();
        $lecturerId = $user?->lecturer_id;
        if (! $lecturerId) {
            abort(403, 'هذا الحساب غير مرتبط بمدرس — تواصل مع مجلس المدرسين.');
        }

        return Faculty::findOrFail((int) $lecturerId);
    }

    /** الشعب التي يدرّس فيها المدرس (أي شعبة فيها صف بإسناد إليه) */
    private function groupIdsFor(Faculty $lecturer): array
    {
        return Timetable::where('faculty_id', $lecturer->id)
            ->select('group_id')
            ->distinct()
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function groupsPayload(Faculty $lecturer): array
    {
        $ids = $this->groupIdsFor($lecturer);

        return Group::whereIn('groups.id', $ids)
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->join('departments', 'departments.id', '=', 'batches.department_id')
            ->orderBy('groups.id')
            ->get(['groups.id', 'batches.name as batch', 'groups.track', 'groups.student_count', 'departments.name as department'])
            ->toArray();
    }

    // GET /api/lecturer/groups — شعبه فقط
    public function myGroups(Request $request)
    {
        return response()->json(['groups' => $this->groupsPayload($this->lecturerFor($request))]);
    }

    // GET /api/lecturer/timetables[?group_id=5] — جدول شعبه (معتمدة + ملغية بختم)
    public function timetables(Request $request)
    {
        $lecturer = $this->lecturerFor($request);
        $groupIds = $this->groupIdsFor($lecturer);
        $groupId = $request->query('group_id');

        $query = Timetable::whereIn('group_id', $groupIds)
            ->where('timetables.status', '!=', 'pending'); // غير المعتمدة (pending) لا يراها المدرس
        if ($groupId !== null && $groupId !== '') {
            $gid = (int) $groupId;
            if (! in_array($gid, $groupIds, true)) {
                return response()->json(['ok' => false, 'message_ar' => 'هذه الشعبة خارج نطاق جدولك'], 403);
            }
            $query->where('group_id', $gid);
        }

        $rows = $query
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->select(
                'timetables.id',
                'timetables.group_id',
                'timetables.course_id',
                'timetables.faculty_id',
                'timetables.hall_id',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.academic_term',
                'timetables.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
                'faculty.full_name AS faculty_name',
            )
            ->orderByRaw("CASE timetables.day_of_week WHEN 'السبت' THEN 1 WHEN 'الأحد' THEN 2 WHEN 'الاثنين' THEN 3 WHEN 'الثلاثاء' THEN 4 WHEN 'الأربعاء' THEN 5 WHEN 'الخميس' THEN 6 ELSE 99 END, timetables.start_time")
            ->get();

        return response()->json(['groups' => $this->groupsPayload($lecturer), 'rows' => $rows]);
    }

    // POST /api/lecturer/timetables/{timetable}/cancel — إلغاء منطقي (status=cancelled)
    public function cancelLecture(Request $request, int $timetable)
    {
        $lecturer = $this->lecturerFor($request);
        $item = Timetable::findOrFail($timetable);

        if ((int) $item->faculty_id !== $lecturer->id) {
            return response()->json(['ok' => false, 'message_ar' => 'هذه ليست محاضرتك — يمكنك إلغاء محاضراتك فقط'], 403);
        }
        if ($item->status === 'cancelled') {
            return response()->json(['ok' => true, 'timetable' => $item, 'already' => true]);
        }

        DB::transaction(function () use ($item, $request) {
            $item->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log($request, 'timetable.cancel', 'timetable', $item->id, ['group_id' => $item->group_id]);
            DB::table('timetable_updates')->insert([
                'group_id' => $item->group_id,
                'timetable_id' => $item->id,
                'update_type' => 'cancel',
                'description' => 'ألغى المدرس محاضرته',
                'created_at' => now(),
            ]);
        });

        return response()->json(['ok' => true, 'timetable' => $item->refresh()]);
    }

    // POST /api/lecturer/timetables/{timetable}/uncancel — التراجع عن الإلغاء
    public function uncancelLecture(Request $request, int $timetable)
    {
        $lecturer = $this->lecturerFor($request);
        $item = Timetable::findOrFail($timetable);

        if ((int) $item->faculty_id !== $lecturer->id) {
            return response()->json(['ok' => false, 'message_ar' => 'هذه ليست محاضرتك'], 403);
        }
        if ($item->status !== 'cancelled') {
            return response()->json(['ok' => true, 'timetable' => $item, 'already' => true]);
        }

        DB::transaction(function () use ($item, $request) {
            $item->forceFill(['status' => 'approved'])->save();
            $this->audit->log($request, 'timetable.uncancel', 'timetable', $item->id, ['group_id' => $item->group_id]);
            DB::table('timetable_updates')->insert([
                'group_id' => $item->group_id,
                'timetable_id' => $item->id,
                'update_type' => 'edit',
                'description' => 'أعاد المدرس تفعيل محاضرته',
                'created_at' => now(),
            ]);
        });

        return response()->json(['ok' => true, 'timetable' => $item->refresh()]);
    }

    // GET /api/lecturer/lecturers — مدرسو شعبه عدا نفسه (للطلبات)
    public function groupLecturers(Request $request)
    {
        $lecturer = $this->lecturerFor($request);
        $groupIds = $this->groupIdsFor($lecturer);

        $ids = Timetable::whereIn('group_id', $groupIds)
            ->select('faculty_id')
            ->distinct()
            ->pluck('faculty_id')
            ->reject(fn ($id) => (int) $id === $lecturer->id)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $rows = Faculty::whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'degree', 'email', 'phone']);

        return response()->json(['lecturers' => $rows]);
    }

    // POST /api/lecturer/timetables/{timetable}/substitute-request — طلب بديل في نفس شعبه
    public function requestSubstitute(Request $request, int $timetable)
    {
        $lecturer = $this->lecturerFor($request);
        $item = Timetable::findOrFail($timetable);
        $groupIds = $this->groupIdsFor($lecturer);

        if ((int) $item->faculty_id !== $lecturer->id) {
            return response()->json(['ok' => false, 'message_ar' => 'هذه ليست محاضرتك'], 403);
        }
        if (! in_array((int) $item->group_id, $groupIds, true)) {
            return response()->json(['ok' => false, 'message_ar' => 'الشعبة خارج نطاق جدولك'], 403);
        }

        $input = $request->validate([
            'substitute_lecturer_id' => ['required', 'integer', Rule::exists('faculty', 'id')],
        ]);

        $substituteId = (int) $input['substitute_lecturer_id'];
        if ($substituteId === $lecturer->id) {
            throw ValidationException::withMessages(['substitute_lecturer_id' => ['لا يمكنك اختيار نفسك بديلًا.']]);
        }

        // البديل يجب أن يدرّس في نفس الشعب التي يدرس فيها (نفس النطاق)
        $teachesInScope = Timetable::where('faculty_id', $substituteId)
            ->whereIn('group_id', $groupIds)
            ->exists();
        if (! $teachesInScope) {
            throw ValidationException::withMessages(['substitute_lecturer_id' => ['المدرس المختار لا يدرّس في أي شعبة من شعبك.']]);
        }

        $openExists = SubstituteRequest::where('timetable_id', $item->id)
            ->where('requested_by_lecturer_id', $lecturer->id)
            ->where('status', 'pending')
            ->exists();
        if ($openExists) {
            throw ValidationException::withMessages(['timetable' => ['يوجد طلب بديل معلّق بالفعل لهذه المحاضرة.']]);
        }

        $req = SubstituteRequest::create([
            'timetable_id' => $item->id,
            'requested_by_lecturer_id' => $lecturer->id,
            'substitute_lecturer_id' => $substituteId,
            'status' => 'pending',
        ]);

        $this->audit->log($request, 'substitute.request', 'timetable', $item->id, [
            'requested_by' => $lecturer->id,
            'substitute' => $substituteId,
        ]);

        return response()->json(['ok' => true, 'request' => $req], Response::HTTP_CREATED);
    }

    // GET /api/lecturer/substitute-requests — الصادرة + الواردة
    public function substituteRequests(Request $request)
    {
        $lecturer = $this->lecturerFor($request);

        $query = fn () => SubstituteRequest::with([
            'lecture:id,group_id,course_id,day_of_week,start_time,end_time',
            'requester:id,full_name',
            'substitute:id,full_name',
        ]);

        $outgoing = $query()->where('requested_by_lecturer_id', $lecturer->id)
            ->orderByDesc('id')->get();
        $incoming = $query()->where('substitute_lecturer_id', $lecturer->id)
            ->orderByDesc('id')->get();

        return response()->json(['outgoing' => $outgoing, 'incoming' => $incoming]);
    }

    // POST /api/lecturer/substitute-requests/{request}/respond — المستهدف يقبل/يرفض
    public function respondSubstitute(Request $request, int $request_)
    {
        $lecturer = $this->lecturerFor($request);
        $sub = SubstituteRequest::findOrFail($request_);

        if ((int) $sub->substitute_lecturer_id !== $lecturer->id) {
            return response()->json(['ok' => false, 'message_ar' => 'هذا الطلب ليس موجّهًا إليك'], 403);
        }
        if ($sub->status !== 'pending') {
            return response()->json(['ok' => false, 'message_ar' => 'ردّ سابق على هذا الطلب بالفعل'], 422);
        }

        $input = $request->validate(['action' => ['required', Rule::in(['accept', 'reject'])]]);

        DB::transaction(function () use ($sub, $input, $request) {
            if ($input['action'] === 'accept') {
                $sub->forceFill(['status' => 'accepted'])->save();
                $lecture = Timetable::lockForUpdate()->findOrFail($sub->timetable_id);
                $lecture->forceFill(['faculty_id' => $sub->substitute_lecturer_id])->save();
                $this->audit->log($request, 'substitute.accept', 'timetable', $lecture->id, [
                    'from' => $sub->requested_by_lecturer_id,
                    'to' => $sub->substitute_lecturer_id,
                ]);
                DB::table('timetable_updates')->insert([
                    'group_id' => $lecture->group_id,
                    'timetable_id' => $lecture->id,
                    'update_type' => 'edit',
                    'description' => 'قُبِل طلب البديل — أُعيد إسناد المحاضرة',
                    'created_at' => now(),
                ]);
            } else {
                $sub->forceFill(['status' => 'rejected'])->save();
                $this->audit->log($request, 'substitute.reject', 'timetable', $sub->timetable_id, ['substitute' => $sub->substitute_lecturer_id]);
            }
        });

        return response()->json(['ok' => true]);
    }

    // POST /api/lecturer/substitute-requests/{request}/cancel — الطالب يلغي طلبه المعلّق
    public function cancelSubstitute(Request $request, int $request_)
    {
        $lecturer = $this->lecturerFor($request);
        $sub = SubstituteRequest::findOrFail($request_);

        if ((int) $sub->requested_by_lecturer_id !== $lecturer->id) {
            return response()->json(['ok' => false, 'message_ar' => 'هذا الطلب ليس لك'], 403);
        }
        if ($sub->status !== 'pending') {
            return response()->json(['ok' => false, 'message_ar' => 'لا يمكن إلغاء طلب مُرّد بالفعل'], 422);
        }

        $sub->forceFill(['status' => 'cancelled'])->save();
        $this->audit->log($request, 'substitute.cancel', 'timetable', $sub->timetable_id, ['requested_by' => $lecturer->id]);

        return response()->json(['ok' => true]);
    }
}
