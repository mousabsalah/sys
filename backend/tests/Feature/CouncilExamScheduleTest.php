<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSession;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouncilExamScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'council'): User
    {
        return User::create([
            'name' => 'Test '.$role,
            'username' => 'exams_beta_'.$role.'_'.uniqid(),
            'email' => uniqid().'@test.local',
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array{FacultyEntity, Department, Batch, Group} */
    private function makeChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية التقنية', 'code' => 'TECH', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'علوم الحاسوب', 'code' => 'CS']);
        $program = Program::create(['name' => 'بكالوريوس تقنية المعلومات', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة 2026', 'student_count' => 60, 'department_id' => $department->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'track' => 'بكلاريوس', 'student_count' => 60]);

        return [$faculty, $department, $batch, $group];
    }

    /** @return array{Hall, Course} */
    private function seedExamRows(int $groupId, string $examDate = '2026-12-20'): array
    {
        $hall = Hall::firstOrCreate(['name' => 'قاعة الامتحانات', 'capacity' => 80, 'hall_type' => 'قاعة', 'status' => 'متاحة', 'equipment' => null]);
        $course = Course::firstOrCreate(['code' => 'CS-EX', 'name' => 'مقرر الامتحان', 'credit_hours' => 3]);

        ExamSession::create([
            'course_id' => $course->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'exam_date' => $examDate, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
            'exam_type' => 'فاينل', 'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'approved',
        ]);
        ExamSession::create([
            'course_id' => $course->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'exam_date' => $examDate, 'start_time' => '12:30:00', 'end_time' => '15:30:00',
            'exam_type' => 'فاينل', 'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'pending',
        ]);
        // فاينل فصل آخر — لا يُحسب على هذا الفصل
        ExamSession::create([
            'course_id' => $course->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'exam_date' => $examDate, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
            'exam_type' => 'فاينل', 'academic_term' => '2026/2', 'semester_id' => null, 'status' => 'approved',
        ]);

        return [$hall, $course];
    }

    public function test_index_groups_exams_by_specialization_with_status_counts(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $this->seedExamRows($group->id);
        $council = $this->makeUser('council');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->getJson('/api/council/exam-schedules?term=2026/1')
            ->assertOk();

        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $mine = collect($groups)->firstWhere('id', $group->id);
        $this->assertSame('دفعة 2026', $mine['batch']);
        $this->assertSame('بكالوريوس تقنية المعلومات', $mine['program']);
        $this->assertSame('علوم الحاسوب', $mine['department']);
        $this->assertSame(2, $mine['counts']['total']);
        $this->assertSame(1, $mine['counts']['approved']);
        $this->assertSame(1, $mine['counts']['pending']);

        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
    }

    public function test_index_forbidden_for_admin(): void
    {
        $admin = $this->makeUser('admin');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/council/exam-schedules')
            ->assertStatus(403);
    }

    public function test_official_exam_sheet_serves_approved_layout_for_group(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $this->seedExamRows($group->id);
        $council = $this->makeUser('council');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->getJson('/api/council/exam-schedules/official?group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('دفعة 2026', $response->json('group.batch_name'));
        $this->assertSame('بكلاريوس', $response->json('group.track'));
        $this->assertSame('بكالوريوس تقنية المعلومات', $response->json('group.program_name'));

        // المعتمدة فقط — pending لا تظهر في الورقة الطباعية (لكلا الفصلين مثل ورقةنا)
        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('approved', $row['status']);
        }

        // اللافتة تُخصَّص للامتحانات مع الاحتفاظ بباقي الورقة
        $this->assertSame('جدول امتحانات نهاية الفصل', $response->json('sheet.title_prefix'));
        $this->assertStringContainsString('{prefix}', $response->json('sheet.title_template'));
        $this->assertSame(true, $response->json('sheet.signatures_enabled'));
        $this->assertArrayHasKey('issued_at', $response->json());
    }

    public function test_official_exam_sheet_forbidden_for_admin(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $admin = $this->makeUser('admin');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/council/exam-schedules/official?group_id='.$group->id)
            ->assertStatus(403);
    }

    public function test_archive_then_unarchive_roundtrip_keeps_pending_flow(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $this->seedExamRows($group->id);
        $council = $this->makeUser('council');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/exam-schedules/archive', ['group_id' => $group->id, 'academic_term' => '2026/1'])
            ->assertOk()
            ->assertJsonPath('archived', 2);

        $this->assertSame(2, ExamSession::where('group_id', $group->id)->where('status', 'archived')->count());
        // الفصل الآخر لم يُحصر
        $this->assertSame(1, ExamSession::where('group_id', $group->id)->where('academic_term', '2026/2')->where('status', 'approved')->count());

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/exam-schedules/unarchive', ['group_id' => $group->id, 'academic_term' => '2026/1'])
            ->assertOk()
            ->assertJsonPath('restored', 2);

        $this->assertSame(2, ExamSession::where('group_id', $group->id)->where('status', 'pending')->count());
    }

    public function test_delete_exam_schedule_removes_only_group_and_term(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $batch2 = Batch::create(['name' => 'دفعة 2025', 'student_count' => 25, 'department_id' => $department->id, 'program_id' => $batch->program_id]);
        $group2 = Group::create(['batch_id' => $batch2->id, 'track' => 'دبلوم', 'student_count' => 25]);
        $this->seedExamRows($group->id);
        $this->seedExamRows($group2->id);

        $council = $this->makeUser('council');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->deleteJson('/api/council/exam-schedules/'.$group->id.'?academic_term=2026/1')
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertSame(0, ExamSession::where('group_id', $group->id)->where('academic_term', '2026/1')->count());
        $this->assertSame(1, ExamSession::where('group_id', $group->id)->where('academic_term', '2026/2')->count());
        $this->assertSame(2, ExamSession::where('group_id', $group2->id)->where('academic_term', '2026/1')->count());
    }
}
