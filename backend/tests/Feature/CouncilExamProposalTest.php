<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Models\Hall;
use App\Models\Program;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CouncilExamProposalTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'council'): User
    {
        return User::create([
            'name' => 'Test '.$role,
            'username' => 'exams_'.$role.'_'.uniqid(),
            'email' => uniqid().'@test.local',
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array{faculty: FacultyEntity, department: Department, batch: Batch, group: Group} */
    private function makeChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية التقنية', 'code' => 'TECH', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'علوم الحاسوب', 'code' => 'CS']);
        $program = Program::create(['name' => 'بكالوريوس تقنية المعلومات', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة 2026', 'student_count' => 40, 'department_id' => $department->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'track' => 'بكلاريوس', 'student_count' => 40]);

        return [$faculty, $department, $batch, $group];
    }

    private function makeHall(int $capacity = 60): Hall
    {
        return Hall::create(['name' => 'قاعة الاقتراح', 'capacity' => $capacity, 'hall_type' => 'قاعة', 'status' => 'متاحة', 'equipment' => null]);
    }

    /** @return array{0: Course, 1: Course} */
    private function seedPlan(int $groupId): array
    {
        $c1 = Course::create(['code' => 'CS101', 'name' => 'مقدمة برمجة', 'credit_hours' => 3]);
        $c2 = Course::create(['code' => 'DB101', 'name' => 'قواعد البيانات', 'credit_hours' => 3]);
        GroupCourse::create(['group_id' => $groupId, 'course_id' => $c1->id, 'sessions_per_week' => 3]);
        GroupCourse::create(['group_id' => $groupId, 'course_id' => $c2->id, 'sessions_per_week' => 3]);

        return [$c1, $c2];
    }

    public function test_context_returns_groups_courses_and_plans(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$c1, $c2] = $this->seedPlan($group->id);
        $council = $this->makeUser('council');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->getJson('/api/council/propose-exams/context')
            ->assertOk();

        $this->assertCount(1, $response->json('groups'));
        $this->assertCount(2, $response->json('courses'));
        $this->assertCount(2, $response->json('plans.'.$group->id));
    }

    public function test_propose_exams_returns_assignments_for_the_plan(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$c1, $c2] = $this->seedPlan($group->id);
        $hall = $this->makeHall(60);
        $council = $this->makeUser('council');

        Http::fake([
            '*/propose-exams' => Http::response([
                'ok' => true,
                'status' => 'OPTIMAL',
                'placed' => 2,
                'assignments' => [
                    ['course_id' => $c1->id, 'code' => 'CS101', 'name' => 'مقدمة برمجة', 'period_id' => 1, 'exam_date' => '2026-12-20', 'day' => 'الأحد', 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'label' => 'صباحًا', 'hall_id' => $hall->id, 'hall_name' => $hall->name, 'academic_term' => '2026/1'],
                    ['course_id' => $c2->id, 'code' => 'DB101', 'name' => 'قواعد البيانات', 'period_id' => 3, 'exam_date' => '2026-12-21', 'day' => 'الاثنين', 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'label' => 'صباحًا', 'hall_id' => $hall->id, 'hall_name' => $hall->name, 'academic_term' => '2026/1'],
                ],
                'unplaced' => [],
                'academic_term' => '2026/1',
            ]),
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/propose-exams', [
                'group_id' => $group->id,
                'from' => '2026-12-20',
                'to' => '2026-12-25',
                'academic_term' => '2026/1',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('placed', 2);
    }

    public function test_proposal_endpoint_is_council_only(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $admin = $this->makeUser('admin');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->postJson('/api/council/propose-exams', [
                'group_id' => $group->id,
                'from' => '2026-12-20',
                'to' => '2026-12-25',
                'academic_term' => '2026/1',
            ])
            ->assertStatus(403);
    }

    public function test_apply_creates_pending_exams_atomically(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$c1, $c2] = $this->seedPlan($group->id);
        $hall = $this->makeHall(60);
        $council = $this->makeUser('council');

        Http::fake(['*/check' => Http::response(['ok' => true, 'conflict' => false, 'type' => 'exam', 'violations' => []])]);

        $payload = ['assignments' => [
            ['course_id' => $c1->id, 'course_code' => 'CS101', 'group_id' => $group->id, 'hall_id' => $hall->id, 'exam_date' => now()->addDays(10)->toDateString(), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'academic_term' => '2026/1'],
            ['course_id' => $c2->id, 'course_code' => 'DB101', 'group_id' => $group->id, 'hall_id' => $hall->id, 'exam_date' => now()->addDays(11)->toDateString(), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'academic_term' => '2026/1'],
        ]];

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/propose-exams/apply', $payload)
            ->assertCreated()
            ->assertJsonPath('created', 2);

        $this->assertSame(2, DB::table('exam_sessions')->where('group_id', $group->id)->where('status', 'pending')->count());
        $this->assertSame(2, DB::table('exam_updates')->count());
    }

    public function test_apply_allows_exam_overlapping_a_lecture_in_same_hall(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$c1, $c2] = $this->seedPlan($group->id);
        $hall = $this->makeHall(60);
        $council = $this->makeUser('council');

        // محاضرة معلّقة في نفس القاعة ونفس الوقت الأسبوعي (الأحد) — فيجب تجاوزها عند فحص الامتحان (أسبوع الامتحانات يعلو)
        $lecturer = Faculty::create(['full_name' => 'د. التجاوز', 'department_id' => $department->id, 'email' => uniqid().'@exempt.local']);
        Timetable::create([
            'course_id' => $c1->id, 'faculty_id' => $lecturer->id,
            'group_id' => $group->id, 'hall_id' => $hall->id,
            'day_of_week' => 'الأحد', 'start_time' => '09:00:00', 'end_time' => '12:00:00',
            'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'approved',
        ]);

        Http::fake(['*/check' => Http::response(['ok' => true, 'conflict' => false, 'type' => 'exam', 'violations' => []])]);

        // 2026-11-22 = الأحد
        $examDate = '2026-11-22';
        $payload = ['assignments' => [
            ['course_id' => $c2->id, 'course_code' => 'DB101', 'group_id' => $group->id, 'hall_id' => $hall->id, 'exam_date' => $examDate, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'academic_term' => '2026/1'],
        ]];

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/propose-exams/apply', $payload)
            ->assertCreated()
            ->assertJsonPath('created', 1);

        // الحمولة المُرسلة للمحرك لا تحمل المحاضرة الأرثوذكسية — استثناء الامتحانات من تعارض المحاضرات
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '"existing":[]')
            || ! str_contains((string) $request->body(), '"lecture"'));

        $this->assertSame(1, DB::table('exam_sessions')->where('group_id', $group->id)->count());
    }

    public function test_apply_rolls_back_everything_on_conflict(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$c1, $c2] = $this->seedPlan($group->id);
        $hall = $this->makeHall(60);
        $council = $this->makeUser('council');

        Http::fake(['*/check' => Http::response(['ok' => false, 'conflict' => true, 'type' => 'exam', 'violations' => [['code' => 'HALL_BUSY_EXAM', 'message_ar' => 'القاعة محجوزة لامتحان', 'severity' => 'hard']]])]);

        $payload = ['assignments' => [
            ['course_id' => $c1->id, 'course_code' => 'CS101', 'group_id' => $group->id, 'hall_id' => $hall->id, 'exam_date' => now()->addDays(10)->toDateString(), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'academic_term' => '2026/1'],
        ]];

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/propose-exams/apply', $payload)
            ->assertStatus(422);

        $this->assertSame(0, DB::table('exam_sessions')->where('group_id', $group->id)->count());
        $this->assertSame(0, DB::table('exam_updates')->count());
    }
}
