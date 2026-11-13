# SYST — نظام إدارة القاعات والجداول الأكاديمية — جامعة AUST

مشروع تخرج: نظام إلكتروني عربي RTL لإدارة حجز القاعات الدراسية وتنظيم الجداول الأكاديمية في الجامعة. النطاق الكامل وتحليل المنظومة في `docs/01-system-analysis.md` (الداتا فلو، المعمارية، خطة التنفيذ، العزل وجودة الكود).

## ما تم تثبيته للمشروع (2026-11-09)

### 1) مهارات (Agent Skills) — على مستوى المشروع

تُثبَّت في `D:\SYST\.agents\skills\` (مش على مستوى الجهاز)، وحالتها موثقة في `skills-lock.json` (26 مهارة):

**(أ) مهارات الـ optimization (17) — من hajibabaie/combinatorial-optimization-skills + tondevrel:**
| المهارة | المصدر | الاستخدام في المشروع |
|---|---|---|
| `timetabling-and-rostering` | hajibabaie/combinatorial-optimization-skills | قلب المشروع: نمذجة وحل مشكلة الجدولة |
| `graph-coloring` | نفس المصدر | جدول الامتحانات = تلوين على conflict graph (طلاب/مدرسين مشتركون) |
| `problem-formulation` | نفس المصدر | تحويل المتطلبات النصية لنموذج optimization رسمي |
| `open-source-solvers` | نفس المصدر | اختيار الـ solver (OR-Tools CP-SAT إلخ) بدون تراخيص تجارية |
| `constraint-handling-techniques` | نفس المصدر | معالجة القيود الهشة/المرنة (soft constraints) |
| `optimization-project-structure` | نفس المصدر | تنظيم كود الـ optimization بشكل قابل لإعادة التشغيل |
| `instance-generation-and-benchmarks` | نفس المصدر | توليد instances للاختبار وقياس الجودة |
| `integer-programming-techniques` | نفس المصدر | ضبط صيغة MIP إن استُخدمت |
| `linearization-techniques` | نفس المصدر | تحويل أي حدود غير خطية لصيغة خطية |
| `milp-modeling-gurobi` | نفس المصدر | بناء MIP إن لزم (مرجع نمذجة، حتى بدون رخصة Gurobi) |
| `multi-objective-optimization` | نفس المصدر | موازنة أهداف الجدولة المتضاربة (راحته الطالب ↔ كفاءة القاعات) |
| `assignment-problems` | نفس المصدر | تعيين قاعات/مدرسين/شعب |
| `set-covering-packing-partitioning` | نفس المصدر | تغطية/تقسيم جلسات على فترات ومقاعد |
| `large-neighborhood-search` | نفس المصدر | تسريع الحلول للحالات الكبيرة جدًا |
| `tabu-search` | نفس المصدر | بديل metaheuristic لو CP-SAT تعثر |
| `job-shop-scheduling` | نفس المصدر | جدولة بعلاقات ترتيب (مثال: محاضرات بترتيب تسلسلي) |
| `ortools` | tondevrel/scientific-agent-skills | دليل عملي لـ OR-Tools و CP-SAT |

**(ب) مهارات التنفيذ (9) — نفسه المستوى project-level:**
| المهارة | المصدر | الاستخدام في المشروع |
|---|---|---|
| `laravel-specialist` | jeffallan/claude-skills (23.8K) | بناء والضبط Laravel: Eloquent، Sanctum، REST APIs، API Resources، الأداء |
| `laravel-tdd` | affaan-m/ecc (11.7K) | Pest/PHPUnit + model factories + HTTP tests — قيادة test-first |
| `laravel-patterns` | affaan-m/ecc (12.7K) | Service layer / queues / caching / API resources patterns |
| `golang-code-style` | samber/cc-skills-golang (46K) | أسلوب كود واضح وثابت للخدمة |
| `golang-testing` | samber/cc-skills-golang (45.3K) | Table-driven + coverage + integration tests للـ `syst-engine` |
| `golang-error-handling` | samber/cc-skills-golang (45.3K) | `%w` wrapping + errors.Is/As + slog — رؤية إنتاجية للخطأ |
| `golang-security` | samber/cc-skills-golang (44.1K) | `gosec` نفس فكرة audit للخدمة |
| `vercel-react-best-practices` | vercel-labs/agent-skills (884K) | أداء React/Vite + patterns حقيقية من Vercel — للواجهة |
| `webapp-testing` | anthropics/skills (201K) | اختبار الشاشات (user flows/evidence لقطات الشاشة) |

### 2) MCP — على مستوى المشروع فقط

`D:\SYST\opencode.json` يعرّف خادمين (project-level):

- **`ortools`** (Jacck/mcp-ortools): `["python", "-m", "mcp_ortools.server"]` — أدوات: `submit_model` / `solve_model` / `get_solution`. **تم اختباره حيًا** (OPTIMAL objective=27.0).
- **`semgrep`** (Semgrep MCP الرسمي، مجاني): `["uvx", "--python", "3.12", "--with", "pydantic==2.11.4", "semgrep-mcp"]` — **Python 3.14 غير مدعوم على حزم قديمة؛ الحل: فرز `uvx` إلى Python 3.12 مع تثبيت `pydantic==2.11.4` عشان `eval_type_backport` تسترجع**؛ فحص الكود بجودة الأمان (5000+ قاعدة SAST) للـ Laravel/Go/React. **يظهر تلقائيًا في قائمة الأدوات بجلسة جديدة.**

- اتثبت عبر `pip install -e C:\Users\Panda\.tools\mcp-ortools` (تم تطبيع باج `setup.py` الذي يعيق التثبيت على Windows: سطر `scripts=['scripts/mcp-ortools.exe']` المفقود).
- الأدوات: `submit_model` / `solve_model` / `get_solution`.
- **تم اختباره حيًا داخل OpenCode** على مسألة توزيع 3 قاعات×3 فترات: النتيجة `OPTIMAL` objective=27.0 (مطابقة للحل اليدوي).
- **مهم**: `opencode.json` يُقرأ عند بدء جلسة جديدة على `D:\SYST`؛ إن لم يظهر في جلسة قديمة، افتح جلسة جديدة على المجلد.

### 3) أدوات مساعدة (تنفيذ)

الأدوات التالية تُتاح/تُفعل تلقائيًا أثناء التنفيذ:

| الأداة | التثبيت | الاستخدام |
|---|---|---|
| **Mermaid CLI** (`mmdc` v12.2.0 — قلوبل) | `npm install -g @mermaid-js/mermaid-cli` (مع `PUPPETEER_SKIP_DOWNLOAD=true`، تشغيل من Edge بدل Chromium) | توليد المخططات الرسمية النهائية (PNG/SVG) للفصول بعد اكتمال الكود: `mmdc -p "C:\Users\Panda\AppData\Roaming\npm\mermaid-puppeteer.json" -i diagram.mmd -o diagram.png` |
| **config puppeteer على Edge** | `C:\Users\Panda\AppData\Roaming\npm\mermaid-puppeteer.json` (بدون BOM!) | `{"executablePath":"C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe","args":["--no-sandbox","--disable-gpu"]}` — Edge مثبت أصليًا على الجهاز، لا يحتاج Chromium |
| نصائح تشغيلية | PowerShell `[IO.File]::WriteAllText` بدل `Set-Content` للنصوص JSON — `Set-Content -Encoding UTF8` على Windows يضيف BOM يفسد `JSON.parse` | — |

## الوثائق

> **ملاحظة**: ملفات `docs/` والفصول الدراسية PDF/DOCX محفوظة محليًا فقط (بناءً على طلب المشرف) — لا تُنشر في الريبو العام. ارجع لملفاتك المحلية لقراءتها.

- `docs/00-problem-formulation.md` — **مستند قديم (superseded)**؛ بقي مرجعًا تاريخيًا فقط — مصدر الحقيقة: `01`/`03`
- `docs/01-system-analysis.md` — تحليل المنظومة الكامل (الداتا فلو، المعمارية، خطة التنفيذ، العزل وجودة الكود)
- `docs/02-use-cases.md` — حالات الاستخدام الـ21 + الأدوار + قواعد العمل R1–R13
- `docs/03-erd.md` — ERD بـ Mermaid + فهارس وCHECKs + قاموس البيانات (17 كيانًا: faculties, departments, programs, periods, halls, courses, course_faculty, faculty, batches, groups, timetables, bookings, users, guard_locks, timetable_updates, exam_sessions, exam_updates)
- `docs/04-dfd.md` — DFD سياقي + Level 1 + Level 2 (P2 التحقق، P4 قفل/فتح، **P8 الامتحانات**) + D13/D14
- `docs/05-demo-scenario.md` — سناريو العرض أمام المشرف/اللجنة (خطوات الفحص ×10 + 13 مسار فشل + 14 سؤالاً دفاعيًا)
- `docs/06-sample-timetables.md` — جداول نموذجية للدفعة الأولى/الثانية/الثالثة (محاضرات) + جدول امتحانات كامل لـ 8 أيام
- `docs/07-identity.md` — الهوية البصرية AUST (لوحة الألوان + الشعار + الخطوط + تطبيقها على الشاشات)
- `assets/logo-aust.jpg` — شعار AUST المعتمد (الحجم 77,821 بايت؛ الأصل محفوظ في `img/شعار الجامة.jpg`)

## القرارات المعتمدة (2026-11-09)

- **الامتحانات داخل v1**: شاشات إعداد/عرض الامتحانات + `EXAM_SESSIONS`/`EXAM_UPDATES` + خدمة التحقق Go بـ `type=exam`.
- **GROUPS (الشعب)**: `batches` صارت حاوية إدارية فقط؛ الجدولة/التحقق/الاستعلامات/الإشعارات كلها على (الدفعة × المسار: دبلوم/بكلاريوس) — `groups(batch_id, track, student_count)` + `UNIQUE(batch_id, track)`.
- **الهرم التنظيمي جامعة ← كلية ← قسم ← دفعة ← شعبة**: جدولا `faculties` و`departments` جديدان؛ `batches.department_id` و`faculty.department_id`؛ `users.faculty_id` للمستخدم؛ **المقررات والقاعات جامعية مشتركة**؛ **admin فقط** يحرر المقررات؛ **guard مركزي** (كل القاعات)؛ **council** يرى الجامعة كلها (ألغي إطار faculty-scoping)؛ **admin** يرى الجامعة + فلتر كلية في اللوحة.
- **وعاء إسناد الأساتذة (`course_faculty`)**: لكل مقرر قائمة أساتذة مصادقين؛ نموذج إعداد الجدول يتساقط مفلتر حسب المقرر؛ **R13** بمعنيين: لا حفظ بسطر مدرّس خارج الوعاء، **ولا حذف لمدرّس من الوعاء طالما له سطر سارٍ في الجدول** (تعديل المحاضرات أولًا)؛ مادة مشتركة بين دبلوم وبكلاريوس بـ أستاذين = سطرا جدول على نفس `course_id`.
- **Go هو مسار الحساب الثقيل منذ v1**: التحقق من التعارض + تجميع التقارير كلها في Go `syst-engine` (HTTP/JSON على localhost)؛ Laravel = تنسيق/CRUD/RBAC/transactions فقط؛ لا منطق تعارض في PHP أبدًا؛ `CheckerClient` interface + `GoChecker` للإنتاج و`FakeChecker` للاختبارات.
- **الخطة الدراسية أولًا (`group_courses`)**: جدول «خطة الشعبة» هو مصدر الاقتراح — يُزرع (128 سطرًا) ويُعدّل من «البيانات الإدارية» (admin)، والاقتراح يستخدمها افتراضيًا مع بقاء الاختيار الحر (council غير مقيد — الجامعة كلها؛ أُلغي قيد `FacultyScope`)).
- **الاعتماد الكلي أو لا شيء (`/api/timetables/bulk`)**: مسار الاقتراح يمحن التعيينات معًا في معاملة واحدة بـ commitTimetable لكل سطر (قفل + Go check + R13)؛ أي تعارض ⇒ rollback كامل — لا «مسودات نصف معتمدة»، مع تفاصيل الأسطر المتعارضة بـ العربية.
- **بيئة التطوير على Windows/XAMPP**: شغّل `php -d opcache.enable_cli=0 artisan serve --port=8765` — OPcache مفعّل لواجهة CLI على نسخ XAMPP يدمج كود قديم صامتًا ويمنع وراثة تعديلات Laravel حتى بعد إعادة التشغيل.
- **الخوارزمية المعتمدة للتوليد**: **Google OR-Tools CP-SAT** — منفّذ فعليًا في البند 7 (2026-11-10) داخل syst-engine (`/propose` ← `solver.py` عبر Python subprocess، UTF-8 مضبوط) لا في مسار الحفظ اليدوي؛ graph coloring كأساس نظري للامتحانات فقط (لا تطبيق إنتاج)، ولا بدائل (GA/SA/MIP) في الإنتاج.
- **الهوية البصرية AUST معتمدة** (2026-11-09): `#B26225` Primary · `#9C4D20` Secondary · `#D4822D` Accent · `#782B19` Dark Brown · `#0A1D3B` Navy · `#FFFFFF` White؛ `assets/logo-aust.jpg` — شعار AUST المعتمد (نسخة)، الأصل في `img/شعار الجامة.jpg`؛ تفاصيل التطبيق على كل شاشة في `07-identity.md`.

## حالة التنفيذ (2026-11-10)

| المرحلة | الحالة | الملاحظات |
|---|---|---|
| 1 — التأسيس (Laravel 12 + React) | ✅ مكتملة | Laravel 12.71 `backend/` (scaffold على SQLite ثم تواصلت بـ MariaDB للمنظومة الفعلية)؛ React TS/Vite RTL مع هوية AUST + شاشة دخول مبدئية؛ `.env` مكتمل لكليهما |
| 2 — النواة (migrations/Models/seeders) | ✅ مكتملة | 17 كيانًا مدفوعًا على MariaDB `aust_graduation`؛ 3 كليات/6 أقسام/2 برامج/8 دفعات (2 شعب لكل دفعة)/42 فترة/20 قاعة/30 مقرر/12 عضو/5 حسابات عينة |
| 3 — الجداول + التعارض (Go syst-engine + P2) | ✅ مكتملة | `engine/` Go (8080) → `POST /check`؛ Laravel يجمع payload من DB ويرسله — verdict عربي hard/soft؛ endpoints: `POST /api/timetables/check` + `POST /api/timetables`؛ transaction + `SELECT FOR UPDATE` + R13؛ Sanctum token end-to-end ✓ |
| 4 — الحجوزات + الحارس + API | ✅ مكتملة v1 | `POST /api/bookings` + `POST /api/status/now` + `POST /api/halls/{id}/lock|unlock` + `GET /api/locks` + `GET /api/dashboard` + `GET /api/reports/utilization` — Sanctum + role middleware ✓ |
| 5 — اللوحة + التقارير | ✅ مكتمل v1 | KPIs اللوحة مثبتة على backend؛ تقارير نسبة استخدام القاعات مع شريط تقدّم برتقالي + تصدير CSV ✓ (Real data!) |
| 6 — واجهة React RTL | ✅ مكتملة v1 | تسجيل دخول + لوحة + قائمة جداول + إنشاء جدول مع تحقق Go + حجوزات + تقارير CSV — RTL/هوية AUST؛ `npm run build` نظيف ✓ |
| 7 — CP-SAT اقتراح تلقائي | ✅ مكتملة (2026-11-10) | `engine/solver.py` (Google OR-Tools CP-SAT: x[c,p,h,f] مع hard constraints كاملة + قيد سعة القاعة + مدرسو course_faculty + تجاهل الموجود + توازن أيام الأسبوع + timeout قابل للضبط)؛ `POST /propose` داخل syst-engine (Python subprocess، UTF-8 مضبوط)؛ Laravel ProposeController + `POST /api/admin/propose-timetable` (شعبة واحدة بـ `group_id` أو عدة شعب تسلسليًا بـ `group_ids`) + `GET .../context` (يُرجع خطة كل شعبة scoped)؛ شاشة «اقتراح تلقائي» (اختيار شعبة/الكل حسب الخطط + مقررات + المسودة بأعمدة الشعبة) + زر «اعتماد الكل» (معاملة واحدة `POST /api/timetables/bulk` — الكل أو لا شيء + rollback)؛ **مُختبأ حيًا: عدة مسارات للشعب (OPTIMAL، 24/48 حصة) + council يرى الجامعة كلها + bulk = 201 ثم 422 بـrollback كامل**. (بيانات الديمو بقيم **16 محاضرة لمخطط CS2024** موجودة في القاعدة بقرار المشرف) |
| 8 — التشطيب + الفصول + الـdemo | ✅ مكتمل 2026-11-10 (بانتظار قبول المشرف) | الفصل الثاني (الأدوار الفعلية للغات) + الفصل الثالث2 (أقسام 3-12..3-16: الكينونات الجديدة · ERD · council · الأدلة) · PDFs مولّدة · ملحق تقني SYST-08 (.docx/.pdf) للجنة · Vitest 4/4 · README (OPcache + CP-SAT + 2026-11-10 إغلاق) |

## جودة الكود (2026-11-10)

- **Laravel Pint** (`vendor/bin/pint app`) — فروع تنسيق للكود الموجود.
- **Larastan/PHPStan** — `phpstan.neon` مستوى 5 — **0 أخطاء** على طبقة `app/` (الاختبارات مستثناة عن بُنية ـ توقيع المتحكمات مثبّتة في CI).
- **oxlint** (React/Vite) — **0 أخطاء** على `frontend/` (17 تحذيرًا في نمط الكتابة القائم، لا أخطاء).
- **Go build + vet** لـ `engine/` — نظيفان (0 مشاكل). **CI** (`.github/workflows/quality-gate.yml`): تشغّل Pint + PHPStan + PHPUnit بمقياس تغطية موجّه لسطر `Lines:` فقط + oxlint + Vitest + `npm run build` + **Go build/vet** على كل push/PR إلى `main`، بثلاث وظائف (backend / engine / frontend) وNode 24 للفرونت إند. **التغطية الحقيقية مقاسة على CI: Lines 37.09% (586/1580)** — الأرضية في CI هي **ratchet عند 35%** (لا تسامح مع تراجع؛ الهدف ولا تزال 60%). انظر «تحقق الجودة في CI» بالأسفل.
- السلسلة: `laravel-specialist` skill + `laravel-patterns` + `golang-code-style` + `ortools` (CP-SAT).
- **سلوك `propose` موثق**: إذا كانت كل الشعب تفشل لأسباب «لا شيء» (لا فترات · لا مقررات · لا قيعان) → ردّ `200 ok:true, placed:0` وليس 503 — مفروض بقاعدة `test_propose_all_skip_still_returns_200_ok_true`.
- **تحقق الجودة هنا (محلي)**: `cd backend && php artisan test` (**45 اختبار · 125 assertion**) · `vendor/bin/phpstan analyse --memory-limit=512M` · `vendor/bin/pint --test` · **`cd engine && go build ./... && go vet ./...`** · `cd frontend && npm test && npm run build` — كلها نظيفة في النسخة الحالية محليًا.
- **تحقق الجودة في CI (مرصود فعليًا 2026-11-12)**: البوابة تعمل على كل push/PR. الجولتان الأوليان فشلتا بسببين حقيقيين (أرضية `max(all %)` تمرر مستحيلًا · Vitest انكسر على Node 20) — أُصلحتا. **تم رصد تشغيل أخضر كامل للبوابة: run `43134526977` على `eba5f0f` — backend ✅ (41/102, Lines 37.09% ≥ 35 ratchet) · engine ✅ · frontend ✅ (oxlint + Vitest + `npm run build`)**.
- **التحسينات والـ AJAX (2026-11-12)**:
  - **الواجهة (AJAX بشكل كامل)**: `api()` يدعم `AbortSignal` الآن · «إدارة المستخدمين» تلغي الطلبات الجارية وتجرّد الردود المتأخرة (seq guard) — لا نتائج قديمة تدهس الأحدث · شاشة «القاعات المتاحة» تُحدَّث تلقائيًا كل 30 ثانية (تتوقف في التبويب المخفي، مع ختم «آخر تحديث») · مكونات مشتركة `SkeletonRows/SkeletonCards/ErrorRetry` (`components/LoadState.tsx`) · أزرار Retry في Dashboard/Timetables/Exams/Bookings/Reports/AdminUsers بدل رسائل الخطأ الصامتة · skeleton بدل «جارٍ التحميل…» في جداول القوائم · تحققت حيًا بحساب الحارس.
  - **قاعدة البيانات**: `ReportController::utilization` تستبعد محاضرات الفصول المؤرشفة (`Timetable::notArchived()`) · `currentFreeHalls()` يحسم «آخر قفل لكل قاعة» في SQL (`MAX(id) GROUP BY hall_id`) بدل سحب كل سجل GuardLock في PHP · **فهارس جديدة**: `timetables(day_of_week)` · `timetables(academic_term)` · `exam_sessions(exam_date)` · `bookings(date)` · `semesters(archived_at)` — EXPLAIN قبل التهجير: full scan (`ALL`) أو filesort في كل الاستعلامات الخمسة المستهدفة؛ بعد التهجير: `ref`/`range` (rows: 2 بدل 16 لمحاضرات السبت) · **قيود CHECK** (MySQL-only، تخطتها اختبارات sqlite): سعة القاعة > 0 · start_time < end_time (محاضرات/امتحانات/حجوزات) · student_count ≥ 0 · تواريخ الفصل مرتبة — **تم التحقق 0 مخالفات قبل التطبيق** · نسخة احتياطية أُخذت قبل ترحيل القاعدة (`storage/backups/aust_graduation_2026-11-12_053537_*.sql`).
  - **القياس**: زمن HTTP اليوم يهيمن عليه ~230-300ms ثابت لكل طلب (سيرفر PHP الداخلي بلا opcache) — الفرق الفعلي للبيانات الحالية ملي ثوانٍ؛ الفهارس تحمي النمو لا الكمون الحالي. الأهمية الحقيقية: صحة البيانات (التقرير صادق الآن) + قابلية التوسع.
- **مجلس المدرسين — الدور الجديد (2026-11-13)**:
  - **الدور `council` («مجلس المدرسين») يرث كل صلاحيات دور `dept` ويستبدلها** — المسارات كلها تحوّلت `role:council` بنطاق **الجامعة كلها**: الجداول، الامتحانات، bulk، الاقتراح التلقائي CP-SAT، القاعات المتاحة، حجز قاعة. دور `dept` أُلغي من الكود والبذور والواجهة (الحسابات التجريبية `قسم_العلوم`/`قسم_الهندسة` حُذفت).
  - **المدرسون (شاشة «إدارة المدرسين» للمجلس)**: يضيف مدرسين بدرجاتهم من قائمة ثابتة (معيد · محاضر · أستاذ مساعد · أستاذ مشارك · أستاذ)، ويربطهم بـ **أكثر من كلية** (جدول `lecturer_faculties` — كليات المدير) عبر قائمة اختيار في الفورم، و department_id صار اختياريًا.
  - **ربط المدرس بالمقررات**: واجهة تعرض مقررات المدرس + الكل للاختيار، مع قاعدة R13 (لا فك ربط لمدرس له `timetables` في نفس المقرر — 422) بنفس نمط الحذف المحروس للقاعات.
  - **الجداول والامتحانات صارت على مسار الاعتماد**: عمود `status` (pending|approved) على `timetables` و`exam_sessions` — الموجود معتمد (لم يتغير السلوك)، كل جديد من المجلس يبدأ **pending**؛ **يرى غير المجلس/المدير المعتمد فقط**؛ pending تحجز موعدها في فحص التعارض؛ **المدير يعتمد عبر شاشة «اعتماد الجداول والامتحانات»** — وزر الاعتماد يفتح الرؤية للجميع · **الامتحانات تمر نفس المسار**.
  - **المدير يشوف كل الجداول** (pending + approved) + شاشة الاعتماد — بدون إنشاء أو تعديل.
  - **الواجهة**: «إدارة المدرسين» للمجلس (CRUD + ربط بالكليات والمقررات في شاشة واحدة) · «اعتماد الجداول والامتحانات» للمدير (موافقتان) · أودعت الشاشات بحالتين: للمحررين رسالة النجاح عن «تم الاعتماد ✓ — ظهر الآن للجميع».
  - **بيانات الديمو**: أُنشئ `مجلس_المدرسين` / `Council@123456` (موثق كمخاطر مقبولة كالمعتاد)؛ تدرس 12 مدرسًا أُسندت لهم درجاتهم العلمية (تلقائيًا) وربطوا بكليات أقسامهم (12 ربطًا `lecturer_faculties`).
  - **تحقق حي كامل**: تسجيل دخول المجلس ✅ · قائمة المدرسين (12) ✅ · إنشاء مدرس بدرجتين وكليتين (كلية العلوم + كلية الهندسة) ✅ · درجة خاطئة ← 422 ✅ · ربط/فك ربط مقرر ✅ · ظهور الجداول (16 صف) والامتحانات (0) ✅ · المدير ← اعتماد 200 ✅ · حسابات dept القديمة مرفوضة 422 ✅ · **45/45 اختبار PHPUnit (125 assertion)** · PHPStan 0 · Pint نظيف · Vitest 4/4 · npm run build نظيف · CI أخضر (مرصود).
- **دور «المدرس» (2026-11-13/14)**:
  - **كل مدرس يُنشأ في «إدارة المدرسين» له حساب دخول إلزامي**: username إجباري في الفورمة، وكلمة المرور تُولَّد عشوائيًا إن تُركت فارغة (تُعرض مرة واحدة بنفس نمط reset-password).
  - **الشاشات المتاحة للمدرس**: «جدولي» (جدول الشعب التي يدرّس فيها + الإجراءات أدناه) · «طلبات البدلاء» (الواردة/الصادرة) · «القاعات المتاحة» (يشوف + يحجز) · «حجوزاتي» · اللوحة.
  - **يرى مدرّس الشعب** التي يسند فيها صفًا (faculty_id = هو) — أي أن الشعبة كاملة تظهر بصفوفها جميعًا مع شارة للمدرسين الآخرين. المعتمد فقط (المعلّق لا يراه المدرس ولا غيره سوى المجلس/المدير).
  - **يلغي محاضرته منطقيًا**: زر «إلغاء» على صفوفه ← status=cancelled → الصف يُستبعد من فحص التعارض ويُبقي في السجل بتاريخ، وشعار رمادي بالواجهة، مع زر «إرجاع» للتراجع (status → approved).
  - **طلب البديل المباشر**: مدرس يطلب من زميل في شعبه نفسه أن يغطي محاضرته (نفس الشعبة) — لا يتدخّل المجلس ولا المدير؛ يرى المستهدف «طلبات البدلاء ← واردة» ويوافق/يرفض. عند القبول يُعاد إسناد المحاضرة للبديل لحظيًا (audit + timetable_updates).
  - **القاعات الفاضية والمحجوزة**: المدرس يرى الأفاتيو «القاعات المتاحة» ويحجز قاعة فارغة من نفس الشاشة (بدون بوابة مجلس/مدير).
  - **ربط الحساب**: `users.lecturer_id` — عمود واحد يربط حساب users بسجل faculty (مدرس) — تحملها auth payload الموحد.
  - **البيانات التجريبية**: 12 حساب مدرس 🎉 (username = البادئة قبل @ في إيميل المدرس، كلمة مرور موحّدة `Lecturer@123456`) — موثقة كمخاطر مقبولة كالمعتاد.
  - **التحقق الحي**: sami.saleh login ✅ · الشعب: دفعة 2024 — دبلوم ✅ · 4 محاضراته ظاهرة ✅ · إلغاء/إرجاع ✅ · طلب بديل لـ layla.ahmed ← إنشاء الطلب 201 ← وارد ← قبول ← أُعيد الإسناد إليها ✅ · الديمو أُعيد لحالته الأصلية بعد التحقق (الطلب صار cancelled بشفافية في audit_logs كدليل).
  - **دورة حساب المدرس — بيد المجلس حصريًا**: المدير (admin) لا يرى حسابات المدرسين في «إدارة المستخدمين» ولا يستطيع تعديل/إيقاف/تفعيل/إعادة تعيين كلمة المرور لأي منها (لا تظهر في القائمة، وكل فعل عليها → `403`). مجلس المدرسين وحده من يملك: الإنشاء (username إلزامي) · **إيقاف الحساب** (تطهير كل توكناته) · **إعادة التفعيل** · **إعادة تعيين كلمة المرور** (مؤقتة تُعرض مرة واحدة + إبطال الرموز القديمة) — كل عملية مسجّلة في `audit_logs` (`lecturer.deactivate|reactivate|reset_password`). تغطّى باختبارين: `test_admin_cannot_see_or_touch_lecturer_accounts` و`test_council_manages_lecturer_account_full_lifecycle`.
  - **PHPUnit 49/49 (145 assertion)** · PHPStan 0 · Pint نظيف · Vitest 4/4 · oxlint 0 errors · build نظيف.
- **Phase 1 — إدارة المستخدمين (2026-11-10)**: migration جديدة (`users.is_active` + `deactivated_at`) وDockerfile `audit_logs`. **Admin فقط** يدير كل الحسابات (`/api/admin/users` CRUD + deactivate/reactivate + reset-password لمرة واحدة) + شاشة «إدارة المستخدمين» في الواجهة (جدول · بحث · فلتر بالدور والحالة · إنشاء · تفعيل/إيقاف · إعادة تعيين). **تعطيل فقط — بلا حذف فعلي**. الحسابات المعطلة تُرفض عند الدخول («الحساب موقوف»). كل فعل إداري يُسجل في `audit_logs` (user_id · action · target · ip · user_agent).
- **Phase 2 — لوحة المدير الكاملة + الفصول الدراسية + النسخ الاحتياطي (2026-11-11)**:
  - **كيان `semesters` جديد** (faculty_id · department_id · batch_id · academic_term · start/end · archived_at، قيد `unique(department_id,batch_id,academic_term)`)؛ **8 فصول 2026/1** وتوليف `semester_id` على `timetables/exam_sessions/bookings` (القاعات مستقلة بقرار المشرف — الخيار «ج»).
  - **الأدمن لا ينشئ جدولًا ولا امتحانًا** (وُحّد المسار `role:council` + يختفي الزر من sidebar؛ الأدمن يقدر يشوف فقط) — يقدر **يضيف كليات وأقسامًا وقاعات ويعدّلها ويحذفها** (الحذف مرفوض عند الربط بـ 422 بأرقام محفوظة).
  - **لوحة المدير الرسومية**: KPIs بعدّادات متحركة + **الخريطة الهندسية للقاعات** (بلاطات ملونة بالشغل/السعة/الحالة + أنيميشن دخول وبلاطات Pulse للقاعات المشغولة) + 4 رسوم Apache ECharts (أعمدة لكل قاعة · دائرة حصة الكلية · أعمدة حسب اليوم · خط المحاضرات والامتحانات عبر الفصول) — كلها في chunk مستقل لا يُحمّل إلا للمدير (`./AdminStats`، ~199 KB gzip مقابل كل الأدوار الأخرى).
  - **الأرشفة**: فصل مؤرشف ← تظهر جداوله وامتحاناته للقراءة فقط مفهرسة `كلية ← قسم ← سنة ← دفعة` (`/admin-archive`)؛ إلغاء الأرشفة مسموح؛ أرشفة أي فصل تُشغّل **نسخة احتياطية تلقائية** (`syst:backup` → `storage/backups/aust_graduation_*.sql`) وتحفظ فقط آخر 10 نسخ.
  - **النسخ الاحتياطي اليدوي**: زر «إنشاء نسخة الآن» + قائمة + تحميل مباشر (فحص اسم الملف ضد path traversal).
  - الباك إند: `AdminMasterDataController` (CRUD كليات/أقسام/قاعات/فصول + AuditLog لكل فعل) · `AdminDashboardController` (تجميع `dashboard-stats` و`archived`) · `AdminBackupController` (create/list/download) · `BackupDatabase` artisan command (mysqldump كامل). **PHPStan = 0**، Pint ممر، Vitest **4/4**، oxlint بلا أخطاء.
- **الفصول (محلي فقط، `all/` و`docs/` gitignored)** — الفصل الثاني (الأدوار الفعلية للغات Go/Python/Java) · الفصل الثالث2 (أقسام 3-12..3-16: الكينونات التسع الجديدة + 10 جداول قاموس بنفس النمط + العلاقات الكاملة + council + ما تحقق على الأرض مع الأدلة: CP-SAT، bulk adopt، OPcache) — PDFs مولّدة عبر Word COM.
- **التحسينات الأخيرة (2026-11-10)**:
  - **Code-splitting فعلي للتطبيق كله**: `App.tsx` (~950 سطر) قسّم إلى `types.ts` + `api.ts` + `views/*.tsx` (16 ملفًا مستقلًا) بتحميل **lazy** عبر `React.lazy` و `Suspense`؛ شاشة الدخول تحميل eager (تتطلبها كل الجلسات)؛ الدخول ←Suspense spinner عربي عند استبدال الشاشة.
  - **PurgeCSS للـ Bootstrap 5 RTL** (`postcss.config.js` + safelist واسعة: aust-*, app-*, d-*, col-*, table-*, form-*, …) — **bootstrap CSS انخفض من 225 KB → 88 KB (~31 KB → ~14 KB gzip)** مع بقاء الواجهة مظهرًا متطابقًا (مُتحقق بصريًا).
  - `vite.config.ts` → `manualChunks` function-form — react (~68 KB gzip) وكل view على حدة مكان منطيق.
  - `routes/web.php` → `/` يُعيد 302 إلى `http://127.0.0.1:5173` بدلًا عن صفحة Laravel العامة.
  - نطاق بيانات الديمو نهائيا نظيف: **0 bookings · 0 exams · 16 timetables (CS2024) · 129 group_courses** — كل محاضرة معتمدة من جلسة بقرار المشرف.
  - الفهارس المركّبة مُتحقة: `timetables(hall_id,day_of_week)` · `(faculty_id,day)` · `(group_id,day)` · `bookings(hall_id,date)` · `exam_sessions(hall_id|group_id, exam_date)`.
  - `.gitignore` داخل `backend/storage/framework/*/` و`bootstrap/cache/` يستبعد الملفات المولّدة فعليًا.
- **Backend logic: correctness pass (2026-11-12)**:
  - `existingSlots()` يعيد كتابة مرة واحدة: يستثني المحاضرات والامتحانات **المؤرشفة** (scope `notArchived` على `Timetable`/`ExamSession`) ويقبل `academic_term` كفلتر اختياري، ويستثني الحجوزات **الملغاة** والأرشفة منها (scope `Booking::active()` = confirmed + غير مؤرشف)، ويحدّ حجوزات المحاضرة المتكررة لنفس يوم الأسبوع فقط.
  - `GuardController::lock` كان يفحص `Timetable` بلا `day_of_week` (الحارس كان ممنوع يقفل قيد محاضرات أي يوم) — أُضيف الفلتر + استثني المؤرشف؛ و`unlock` يعيد الحالة لـ«متاحة» فقط إذا كانت «مغلقة» (لا يمس «صيانة» الإدارية).
  - تحقق `after:start_time` على `end_time` في محاضرات/امتحانات/حجوزات (منع مدى زمني مقلوب) + نفس الفحص داخل المحرك (`INVALID_TIME_RANGE` hard).
  - commit* يربط `semester_id` من الفصل النشط للدفعة (`SemesterResolver`) — الأرشفة الآن تحتوي كل محاضرة/امتحان جديد.
  - فحص التعارض أصبح يأخذ قفل الشعبة ثم المدرس (إضافة لقفل القاعة) — إغلاق سباق شعبة-متزامن عبر قاعتين؛ ترتيب قفل ثابت (قاعة ← شعبة ← مدرس).
  - `/api/syst-engine/*` خلف `auth:sanctum`؛ `BookingController::index` بدون بارامترات → أحدث 100 حجز؛ `weeklyExisting($term)` يستثني المؤرشف.
  - جديد: `App\Services\Academic\ArabicDay` (مصدر واحد ليوم الأسبوع) + `SemesterResolver`؛ إصلاح `api.php` (تلف ترميز سابق — أُعيد كتابته نظيفًا).
- **Frontend (2026-11-12)**:
  - إزالة الحقول المعبأة مسبقًا في شاشة الدخول (كانت كلمة مرور المدير ظاهرة في الكود!)؛ تواريخ صلبة استُبدلت بتاريخ اليوم الحالي؛ «الحجوزات» تعرض أحدث الحجوزات بدل يوم محدد.
  - Fix: اختبار الخروج لا يعتمد على التعبئة المسبقة؛ `App.test.tsx` — **4/4**.
- **Logical correctness pass 2 (2026-11-12)** — بعد مراجعة مستقلة (reviewer subagent):
  - **فتحت ثغرة مصادقة حرجة**: `RoleAuthMiddleware` كان يفحص `role` فقط و`sanctum.expiration=null` — أي حساب مدير معطّل يحتفظ بوصول كامل للأبد. أُضيف `EnsureUserIsActive` middleware على كل مسارات sanctum + `deactivate`/`resetPassword` يمسحان توكنات الهدف داخل transaction.
  - **500 → 422** في الحذف المرتبط (فصول جديدة تربط faculty/department + bookings حين حذف فصل) + `assertAcademicChain` ( batch→department→faculty ) + `assertAcademicTermUnique` على الإنشاء والتعديل + محاذاة القواعد للمخطط (`faculties.description` 255 · `halls.name` 50 · `faculties.name` فريد).
  - **لوحة الأدمن تحترم الأرشفة بالكامل**: كل الإحصاءات (KPIs/per-hall/day/faculty/التركز semesters) تستبعد الفصول المؤرشفة عبر scopes.
  - النسخ التلقائي: BackupGateway قابل للحقن (mysqldump) + backup **قبل** كتابة archived_at + فشل النسخ مسجّل دائمًا في `audit_logs` (`backup.auto_failed`) ولا يرمي أبدًا + throttle (archive 12/د، backup/create 6/د) + أذونات 0700/0600 + أذونات 0600 للملف المؤقت وحذفه بانضباط في `finally`.
  - Laravel: 45 اختبار PHPUnit (AdminSecurityTest + ExampleTest) — المصادقة، التعطيل، حذف التوكنات، 403/422، تجميد المؤرشف(مساران)، مسارا نجاح/فشل النسخ، خطة الشعبة، تنزيل النسخ الاحتياطية، throttle الدخول(5 محاولات)، عزل المحرك (ENGINE_ERROR island — فرعا Throwable وok:false كلاهما)، عقد EngineClient (503→ok:false لا يرمي).
  - الواجهة: خطأ تحميل لوحة المدير يُظهر الآن مع زر «إعادة المحاولة» · AdminPlans يفصل رسالة الخطأ باللون الأحمر · debounce 300ms في بحث المستخدمين · قوائم من master-data الحية · تأكيد على الأرشفة/الإيقاف/إعادة التعيين · إجمالي الحسابات ظاهر للمدير.
- **Verified live**: duplicate 422 بأكواد HALL_BUSY/FACULTY_OVERLAP/GROUP_OVERLAP · admin 403 · مدى مقلوب 422 · **أرشفة الفصل ← 201 محاضرة → إلغاء الأرشفة** · guard now=200 · passthrough 401 مع JSON.
- **مخاطر مقبولة موثقة (للعرض أمام اللجنة على شبكة محلية)**:
  - `sanctum.expiration = null` — التوكنات لا تنتهي صلاحيتها أبدًا؛ التعويض: `EnsureUserIsActive` middleware يفحص `is_active` على كل طلب + `deactivate`/`reset-password` يمسحان التوكنات. أي توكن مسروق لمستخدم فعّال يظل نافذًا حتى تعطيل الحساب — مقبول للعرض المحلي، **غير** مقبول لنشر الإنترنت.
  - النسخ الاحتياطية (`storage/backups/*.sql`) كاملة وغير مشفرة وتحتوي PII + bcrypt hashes؛ المحمية بـ`0700`/`0600` والمجلد خارج Git؛ **لا تنسخها لوسيط/اتصال دون تشفير**.
  - الحسابات في الـ seed معروفة (`Admin@123456`…) — **إلزامية تغييرها قبل أي عرض حقيقي**.
  - `APP_DEBUG=false` في `.env` الحالي (مسار dev)؛ أعده `true` فقط محليًا.
  - جدول `propose` متعدد الشعب لا يُنصح بتنفيذه أمام اللجنة (قد يتجاوز مهلة PHP) — استخدم «اقتراح لشعبة واحدة» فقط في العرض.
  - قاعدة البيانات تُدار اليوم بـ`root` بكلمة مرور فارغة على `127.0.0.1:3306` (XAMPP محلي) — **ليست صالحة لأي شبكة خارج الجهاز**؛ الخادم الإنتاجي يحتاج مستخدم MySQL مخصص بكلمة مرور وصلاحيات محددة على `aust_graduation` فقط.
  - `config/cors.php` يفتح `allowed_origins => ['*']` — مناسب لمعاينة محلية بالـ token عبر `Authorization` (لا cookies)، لكن يجب تثبيته على أصل الواجهة الحقيقي لأي نشر مشترك.
- **Frontend tests (Vitest + Testing Library)** — `npm test`: **4/4 نجحت** (شاشة الدخول · تسجيل الدخول ← لوحة التحكم ببيانات KPI · خطأ عند فشل الدخول · الخروج ← الدخول).
- **الملحق التقني للجنة (محلي، `all/` و`docs/`)** — `SYST-08-technical-reference-appendix.docx|.pdf` (380 KB): البنية · المسارات كاملة بأدلة حية · سيناريو الديمو · القرارات.

## القاعدة التشغيلية

مصدر الحقيقة للنطاق والتصميم: `docs/03-erd.md` (النموذج) ← `docs/01-system-analysis.md` (التحليل) ← `docs/02-use-cases.md` (الحالات). أي كود يبدأ من هذه الوثائق بعد اعتماد المشرف عليها؛ `00-problem-formulation.md` يُحفظ للوثيقة التاريخية لـ formulation (CP-SAT دخل فعليًا مع البند 7).
