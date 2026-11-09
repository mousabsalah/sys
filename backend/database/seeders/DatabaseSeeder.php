<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseFaculty;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── البرامج والفترات ──
        $morning = Program::create(['name' => 'صباحي', 'description' => 'الفترة الصباحية 07:00–14:00']);
        $evening = Program::create(['name' => 'مسائي', 'description' => 'الفترة المسائية 16:00–22:00']);

        foreach (['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'] as $i => $day) {
            Period::create(['program_id' => $morning->id, 'day_of_week' => $day, 'start_time' => '07:00:00', 'end_time' => '09:00:00', 'label' => 'الحصة الأولى', 'sort_order' => 1]);
            Period::create(['program_id' => $morning->id, 'day_of_week' => $day, 'start_time' => '09:15:00', 'end_time' => '11:15:00', 'label' => 'الحصة الثانية', 'sort_order' => 2]);
            Period::create(['program_id' => $morning->id, 'day_of_week' => $day, 'start_time' => '11:30:00', 'end_time' => '13:30:00', 'label' => 'الحصة الثالثة', 'sort_order' => 3]);
            Period::create(['program_id' => $morning->id, 'day_of_week' => $day, 'start_time' => '13:30:00', 'end_time' => '14:00:00', 'label' => 'الحصة الرابعة', 'sort_order' => 4]);

            Period::create(['program_id' => $evening->id, 'day_of_week' => $day, 'start_time' => '16:00:00', 'end_time' => '18:00:00', 'label' => 'الفترة الأولى', 'sort_order' => 1]);
            Period::create(['program_id' => $evening->id, 'day_of_week' => $day, 'start_time' => '18:15:00', 'end_time' => '20:15:00', 'label' => 'الفترة الثانية', 'sort_order' => 2]);
            Period::create(['program_id' => $evening->id, 'day_of_week' => $day, 'start_time' => '20:30:00', 'end_time' => '22:00:00', 'label' => 'الفترة الثالثة', 'sort_order' => 3]);
        }

        // ── الهرم التنظيمي: 3 كليات / 6 أقسام ──
        $sci = FacultyEntity::create(['name' => 'كلية العلوم', 'code' => 'SCI', 'description' => 'كلية العلوم والرياضيات وعلوم الحاسوب']);
        $eng = FacultyEntity::create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'description' => 'كلية الهندسة والتكنولوجيا']);
        $bus = FacultyEntity::create(['name' => 'كلية إدارة الأعمال', 'code' => 'BUS', 'description' => 'كلية إدارة الأعمال والمالية']);

        $departments = [
            [$sci->id, 'علوم الحاسوب', 'SCI-CS'],
            [$sci->id, 'الرياضيات', 'SCI-MATH'],
            [$eng->id, 'الهندسة الكهربائية', 'ENG-EEE'],
            [$eng->id, 'الهندسة المدنية', 'ENG-CE'],
            [$bus->id, 'التسويق', 'BUS-MKT'],
            [$bus->id, 'الإدارة العامة', 'BUS-PUBAD'],
        ];

        $departmentIds = [];
        foreach ($departments as [$facultyId, $name, $code]) {
            $departmentIds[] = Department::create(['faculty_id' => $facultyId, 'name' => $name, 'code' => $code])->id;
        }

        // ── 8 دفعات (شعب: دبلوم + بكلاريوس) ──
        $batchNames = ['دفعة 2022', 'دفعة 2023', 'دفعة 2024', 'دفعة 2025', 'دفعة 2026', 'دفعة 2024 مساء', 'دفعة 2025 مساء', 'دفعة 2026 مساء'];
        $batchIds = [];
        foreach ($batchNames as $i => $name) {
            $isEvening = str_contains($name, 'مساء');
            $batchIds[$i] = Batch::create([
                'name' => $name,
                'student_count' => 0, // يُحسب = مجموع شعبها
                'department_id' => $departmentIds[$i % count($departmentIds)],
                'program_id' => $isEvening ? $evening->id : $morning->id,
            ])->id;

            foreach (['دبلوم', 'بكلاريوس'] as $track) {
                Group::create([
                    'batch_id' => $batchIds[$i],
                    'track' => $track,
                    'student_count' => 30 + ($track === 'بكلاريوس' ? 20 : 0) + ($i * 3),
                ]);
            }
        }

        // تحديث إجمالي الدفعة (sum of groups)
        foreach ($batchIds as $id) {
            Batch::where('id', $id)->update(['student_count' => Group::where('batch_id', $id)->sum('student_count')]);
        }

        // ── 20 قاعة عينة + 2 معمل ──
        $halls = [
            ['قاعة 1', 120, 'قاعة', 'جهاز عرض'],
            ['قاعة 2', 90, 'قاعة', 'جهاز عرض'],
            ['قاعة 3', 60, 'قاعة', 'جهاز عرض'],
            ['قاعة 4', 50, 'قاعة', null],
            ['قاعة 5', 45, 'قاعة', 'سبورة + جوهرة'],
            ['قاعة 6', 40, 'قاعة', null],
            ['قاعة 7', 35, 'قاعة', null],
            ['قاعة 8', 30, 'قاعة', 'جهاز عرض'],
            ['قاعة 9', 25, 'قاعة', 'جهاز عرض'],
            ['قاعة 10', 120, 'قاعة', 'جهاز عرض + P.A نظام صوتي'],
            ['قاعة 11', 75, 'قاعة', null],
            ['قاعة 12', 55, 'قاعة', 'سبورة'],
            ['مدرج أ', 150, 'قاعة', 'نظام عرض ضخم + مقاعد خشبية'],
            ['مدرج ب', 130, 'قاعة', 'نظام عرض'],
            ['معمل رياضيات 1', 40, 'معمل', 'سبورة + كمبيوتر للمدرس'],
            ['معمل هندسة 1', 30, 'معمل', 'أجهزة حاسوب + شاشات'],
            ['معمل إدارة 1', 45, 'معمل', 'أجهزة حاسوب'],
            ['معمل علوم 1', 35, 'معمل', 'مقاعد تجريبية'],
            ['مكتبة 1', 25, 'قاعة', 'هادئ + طاولة بحث'],
            ['قاعة الندوات', 80, 'قاعة', 'جهاز عرض + نظام صوتي'],
        ];
        foreach ($halls as [$name, $capacity, $type, $equipment]) {
            Hall::create([
                'name' => $name,
                'capacity' => $capacity,
                'status' => 'متاحة',
                'hall_type' => $type,
                'equipment' => $equipment,
            ]);
        }

        // ── 30 مقررات عينة ──
        $courses = [
            ['CS101', 'مقدمة في علوم الحاسوب', 3],
            ['CS201', 'هياكل البيانات', 4],
            ['CS202', 'قواعد البيانات', 3],
            ['CS203', 'شبكات الحاسب', 3],
            ['CS204', 'أنظمة التشغيل', 3],
            ['CS301', 'خوادم الويب', 3],
            ['CS302', 'الذكاء الاصطناعي', 3],
            ['CS401', 'مشروع التخرج', 6],
            ['MATH101', 'حساب التفاضل والتكامل', 3],
            ['MATH201', 'الجبر الخطي', 3],
            ['MATH301', 'الإحصاء والاحتمالات', 4],
            ['MATH102', 'الهندسة التفاضلية', 2],
            ['EEE101', 'دوائر كهربائية', 3],
            ['EEE201', 'دوائر الإلكترونيات', 3],
            ['EEE301', 'نظريات الإشارة والنظم', 3],
            ['CE101', 'ميكانيكا كلاسيكية', 4],
            ['CE201', 'مقاومة المواد', 4],
            ['CE301', 'الخرسانة والأساسات', 3],
            ['MKT101', 'مبادئ التسويق', 3],
            ['MKT201', 'التسويق الرقمي', 3],
            ['MKT301', 'إدارة العلامات التجارية', 3],
            ['FIN101', 'مبادئ المحاسبة', 3],
            ['FIN201', 'محاسبة التكاليف', 3],
            ['BUS101', 'مبادئ الإدارة', 3],
            ['BUS201', 'إدارة الموارد البشرية', 3],
            ['BUS301', 'الاستراتيجية والأداء', 3],
            ['ECON101', 'مبادئ الاقتصاد', 2],
            ['ECON201', 'الاقتصاد الجزئي', 4],
            ['PS101', 'مهارات التواصل', 2],
            ['PS102', 'كتابة تقارير', 1],
        ];
        foreach ($courses as [$code, $name, $hours]) {
            Course::create(['code' => $code, 'name' => $name, 'credit_hours' => $hours, 'required_equipment' => null]);
        }

        // ── 12 عضو هيئة تدريس عينة ──
        $memberIds = [];
        $members = [
            ['د. أحمد عبد الله', 0, 'ahmed.abdullah@aust.edu.sd'],
            ['د. سارة محمد', 0, 'sara.mohammed@aust.edu.sd'],
            ['د. عمر عثمان', 0, 'omar.osman@aust.edu.sd'],
            ['د. فاطمة إبراهيم', 0, 'fatma.ibrahim@aust.edu.sd'],
            ['د. يوسف عبد الرحمن', 1, 'yousef.abdelrahman@aust.edu.sd'],
            ['د. مريم حسن', 1, 'maryam.hassan@aust.edu.sd'],
            ['د. سامي صالح', 2, 'sami.saleh@aust.edu.sd'],
            ['د. ليلى أحمد', 2, 'layla.ahmed@aust.edu.sd'],
            ['د. حمدي عبد المجيد', 3, 'hamdy.abdelmaguid@aust.edu.sd'],
            ['د. آمال حسين', 4, 'amal.hussein@aust.edu.sd'],
            ['د. عبد الله الجعلي', 5, 'abdullah.jagl@aust.edu.sd'],
            ['د. منار الطيب', 5, 'manar.altayeb@aust.edu.sd'],
        ];
        foreach ($members as [$fullName, $deptIndex, $email]) {
            $memberIds[] = Faculty::create(['full_name' => $fullName, 'department_id' => $departmentIds[$deptIndex], 'email' => $email, 'phone' => '+249120000000'])->id;
        }

        // ── وعاء الأساتذة: كل مقرر له 1–3 مصادق ──
        $courseIds = Course::pluck('id')->all();
        foreach ($courseIds as $ci => $courseId) {
            $pool = [$memberIds[$ci % count($memberIds)], $memberIds[($ci + 5) % count($memberIds)]];
            if ($ci % 3 === 0) {
                $pool[] = $memberIds[($ci + 7) % count($memberIds)];
            }
            foreach ($pool as $facultyId) {
                CourseFaculty::firstOrCreate(['course_id' => $courseId, 'faculty_id' => $facultyId]);
            }
        }

        // ── حسابات النظام ──
        $all = [
            ['مدير النظام', 'مدير_النظام', 'admin@aust.edu.sd', 'Admin@123456', 'admin', null, null],
            ['قسم كلية العلوم', 'قسم_العلوم', 'dept.sci@aust.edu.sd', 'Dept@123456', 'dept', $sci->id, null],
            ['قسم كلية الهندسة', 'قسم_الهندسة', 'dept.eng@aust.edu.sd', 'Dept@123456', 'dept', $eng->id, null],
            ['الحارس الجامعي', 'حارس_الجامعة', 'guard@aust.edu.sd', 'Guard@123456', 'guard', null, null],
            ['دفعة علوم الحاسوب 2024', 'دفعة_CS2024', 'batch.cs2024@aust.edu.sd', 'Batch@123456', 'batch', null, $batchIds[2]],
        ];

        foreach ($all as [$name, $username, $email, $password, $role, $facultyId, $batchId]) {
            User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'faculty_id' => $facultyId,
                'batch_id' => $batchId,
            ]);
        }
    }
}
