# ASSAB ERP — SLICES 04 + 05 — SINGLE SEQUENTIAL CORRECTION & VERIFICATION ORDER v2.0
## أمر واحد شامل لـGemini Pro High — إغلاق تقرير AUDIT WORK: NEEDS CORRECTION

**المنفذ:** Gemini Pro High / Antigravity — Windows، داخل محادثة العمل الحالية أو جلسة جديدة مع نفس مجلد Backend.  
**المستودع:** `mohameelsherbini/Assab`؛ **المسار المحلي:** `D:\claude\ASSAB ERP GITHUB BACKEND ANTIGRAVITY\Assab`.  
**الفرع المتوقع:** `task/s1-12-dashboard-auth`؛ **Foundation HEAD المتوقع:** `41f03a31929d29fdbb494ef906f8cbe80916c9ad`.  
**التصميم المرجعي:** `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1`؛ **تصريح التنفيذ السابق:** `docs/sprint-01/ASSAB_S105_S112_IMPLEMENTATION_AUTHORIZATION_2026-10-12.md`.  
**المراجع الإلزامية:** العقد المعتمد، blueprint المعتمد، خطة `ASSAB_S04_S05_GEMINI_UNIFIED_EXECUTION_PROMPT_v1_2.md`، تقريرك الأخير، و**أحدث تقرير AUDIT WORK المستقل `NEEDS CORRECTION`** المرفق في سياق المهمة.  
**الحالة الفعلية:** لم تُقبل Slice 04/05؛ لا تُعدّل حالة التدقيق إلى PASS استنادًا إلى تقييمك الذاتي. التنفيذ المحلي المصرح به فقط. التصحيحات المتعلقة بملفات محمود المشتركة أو خارج النطاق تتطلب موافقة صريحة *قبل تعديلها*.

## 0. قاعدة التنفيذ الأساسية — شرط صارم غير قابل للتجاوز

هذه **مهمة تصحيح وإغلاق متطلبات قائمة، وليست إعادة تنفيذ من الصفر أو تصميمًا جديدًا**. اتبع التسلسل **G00.01 → G00.02 → ... → G12.xx**. بعد **كل بند ذري**:

1. اكتب ماذا فعلت والملفات التي اختلفت بالفعل؛ اعرض diff محدودًا.
2. نفّذ اختبار قبول محددًا للبند، يشمل حالة فشل أمنية إن كانت مناسبة، وMariaDB بعمليات واتصالات مستقلة حين يتعلق البند بسباق أو أقفال؛ لا تستبدل الاختبار الإنتاجي بمحاكاة لا تختبر المسار الفعلي.
3. افحص النتيجة في الكود والسجلات و**قاعدة البيانات الفعلية**، مع نتائج الطلبات وexit codes، وتحقق أن Assertions تكتشف العيب المقصود؛ تجنب نتائج fixture مفبركة أو `PASS` ثابتة.
4. اربط البند بـ`Run ID` وملف اختبار وتوقيت وبصمات SHA-256 للكود والاختبار عند بداية ونهاية التشغيل، وسجل DB/cache identity ونسخة MariaDB/connection IDs حين يلزم.
5. اعرض `PASS` للبند فقط عند **التنفيذ + اختبار القبول + فحص الحالة النهائية + دليل صحيح + التأكد من الحدود والموافقات**. وإلا `OPEN/FAILED/BLOCKED/NOT_VERIFIED`.
6. **ممنوع بدء البند التالي ولو داخل البوابة نفسها قبل إغلاق السابق `PASS` فعليًا.** لا تتجاوز بندًا، ولا تحوّله إلى `DEFERRED` إلا إذا أشار التصميم المعتمد صراحةً إلى مرحلة أخرى؛ التوقف على BLOCKED يحتاج قرار صاحب الصلاحية. البنود التوثيقية فقط تتطلب تحققًا من الملفات، ولا نعيد اختبارات كاملة بلا تغيير وظيفي.

شغّل بوابة تحقق محلية **fail-closed** لا تسمح بنتيجة `READY FOR AUDIT` ما دام أي بند إلزامي ناقصًا؛ هذه الأداة تفحص الأدلة ولا تعتبر فحصًا مستقلًا لجودة الاختبار. لا تستخدم كودًا من نوع `Concurrency_Status = PASS` في مولِّد المصفوفة دون فحص artifact فعلي. لا تكتب نتيجة نجحت قبل رؤية exit code وتحقق SQL. **نفّذ التسلسل آليًا قدر الإمكان دون طلب موافقتي بين كل بند ناجح؛ توقف فقط عند حاجز تصريح/تصميم/أمان حقيقي، ثم أرسل الحالة كاملة.**

**قيود مطلقة:** لا Stage/Commit/Push/PR/Merge/Rebase/Reset/Clean/Force Push، لا نشر أو تفعيل إنتاجي، لا تغيير Database غير تجريبية محددة، لا Dashboard/Flutter، لا SSO/Invitations/Impersonation remediation، لا تمويل/Idempotency/Global Sanctum TTL، لا بدء Slice 06/07 أو استحداث بنية/جدول/queue بلا إذن؛ لا حذف أو استبدال العمل غير المتتبع. احتفظ بـLegacy Admin وMobile/Cashier/BranchManager وعمل Slices 01–03 دون تغيير غير مبرر. تظل `admin.session_families_enabled=false` افتراضيًا وStrict Admin Guard لـSlice 07، والقبول الفني لمحمود.

## G00 — تثبيت الحالة والمرجع والتصريح (READ-ONLY قبل أي تعديل)

- [ ] **G00.01 — الهوية:** نفّذ `git branch --show-current`, `git rev-parse HEAD`, `git status --porcelain=v1`, `git diff --stat` و`git diff`؛ سجّل staged/untracked، وSHA للأصول الحالية. قارن بآخر تقرير AUDIT WORK؛ لا تعتبر GitHub SHA دليلًا على كود Windows غير الملتزم.
- [ ] **G00.02 — مرجع ثابت:** اقرأ كامل التصميم المعتمد، تصريح التنفيذ، خطة v1.2، تقرير التدقيق المستقل الأخير. حوّل **كل** ملاحظة P1/P2/P3 وكل متطلب S04/S05 وكل C01–C20 والاختبارات الإضافية إلى أرقام لا تسقط؛ سجّل المصدر والحالة السابقة. إذا لم يكن تقرير التدقيق متاحًا نصيًا، **توقف واطلبه**.
- [ ] **G00.03 — النسخة المرجعية:** سجل المصدر والاختبارات والبصمات الحالية (بما في ذلك أحدث `AuthService.php`)، Snapshot نظيف من `git status` وملفات الدليل في مجلد خارج repo؛ لا تسوِّ شجرة العمل باستخدام Git. سجّل فرق تغييرات هذه المهمة عن Slice 01–03.
- [ ] **G00.04 — المعمل:** تحقق أن الاختبارات تعمل على DB معزولة فعلًا، MariaDB 11.4.12/InnoDB `127.0.0.1:3307` وLaravel Database Cache مشترك والاتصالات المستقلة ومستخدم الاختبار والصلاحيات وIsolation level. لا تعرض كلمات المرور أو APP_KEY في أوامر أو logs. اختبارات state-changing خارج المعمل ممنوعة.
- [ ] **G00.05 — Writer Inventory:** حدد المسارات الفعلية وCall Graph لكل User/Company/Membership status writer، Login/2FA، Refresh، Logout بنوعيه، Session DELETE، Change/Reset/Forgot/Resend، `CredentialSyncService::pullFromMobile/pushToMobile`، Device cleanup؛ لكل writer: `Writer_ID`, route/service, owner, DB connection, transaction, row lock sequence, effect, permissions, race dependencies. لا تفترض أن تعليق الشركة وتعطيل العضوية مجرد تغيير حالات مستقل.
- [ ] **G00.06 — تصريح الملفات المشتركة:** افحص تحديدًا `Modules/Admin/app/Http/Controllers/Admin/CompanyController.php` و`Modules/Admin/app/Http/Controllers/Company/UserController.php` وخدمات عضوية/حسابات محمود. صنف كل تعديل مطلوب: **داخل التفويض المؤكد** أو **يحتاج تصريحًا صريحًا**. إذا كان إصلاح P1 يحتاج تعديل ملف مشترك/ملف عمل محمود غير مصرَّح به، **توقف قبل أي تعديل على ذلك الملف أو الانتقال إلى G01**؛ قدم minimal patch proposal + العقد + الاختبارات + سبب عدم إمكانية الإغلاق دونه. لا تعتبر طلب إعداد هذه الرسالة تصريح تعديل لهذه الملفات.
- [ ] **G00.07 — مصفوفة مصدر الحقيقة:** أنشئ `S04-S05_SEQUENTIAL_CORRECTION_MATRIX.json` خارج repo، مع `ID,Parent,AuditFinding,ApprovedRequirement,SourcePath,AuthorizedScope,Writer_ID,ExactChange,ExpectedResult,TestPath,NegativeTest,ConcurrencyTest,OrderA,OrderB,ActualRunId,Pid,DbConnId,SqlVerification,SourceHashStart,SourceHashEnd,TestHash,EvidencePath,Status,Blocker,VerifiedAt`؛ أنشئ validator مستقل عن التقرير، default = `NOT_VERIFIED`. سجل قائمة البنود بعدد ثابت، وارفض إسقاط IDs أو وضع PASS جماعي. احتفظ بالنسخة الأصلية والتغييرات.
- [ ] **G00.08 — فحص نطاق التعديل:** اقترح أصغر إصلاح مرتبط بكل finding، وارفض أي توسع أو تغيير تصميمي لم يُعتمد. وثق قرار `READY FOR G01` فقط إذا اكتملت كل بنود G00 ولم يظهر حاجز موافقة.

## G01 — P1: ربط إبطال الشركة/العضوية بأهلية Login/2FA/Refresh (الأولوية القصوى)

- [ ] **G01.01 — تحديد الكتابة الحقيقية:** راجع في الكود الفعلي `CompanyController.php` حول suspension و`Company/UserController.php` حول membership disable وأي خدمات/Observers تستدعى داخلهما، مع شركة متعددة، مستخدم منصة، العضوية الخاصة بكل شركة، والروابط ذات الصلة. عرّف الحالة المعتمدة التي تستوجب رفض دخول أو إبطال عائلة بدل اختراع قاعدة «شركة واحدة لكل مستخدم».
- [ ] **G01.02 — عقود الأهلية:** ثبّت جدول truth table لحالة User وCompany وMembership والدور والسياق عند **Login، إكمال 2FA، Direct password+code، Refresh**. وضح كيف تُقيَّم عضوية الشركة المباشرة/المتعددة، ومتى يبقى مستخدم المنصة مؤهلًا. ثبت رموز HTTP وفق العقد ولا تقبل bypass عبر company context مجهول.
- [ ] **G01.03 — قفل/إبطال production writers:** إذا التصريح يسمح، اجعل تغيير أهلية الشركة/العضوية ينسق مع عائلات Admin المتأثرة، ويمنع صدور successor مخالف بعد commit؛ تعامل مع الأقفال بترتيب متماثل لا يخلق cycle عبر مستخدمين/عضويات. إذا تعديل writer خارج التفويض فـ`BLOCKED` وSTOP، لا تصنع مسار اختبار مصطنعًا بدل إصلاح production writer.
- [ ] **G01.04 — Post-lock eligibility:** تأكد أن Login/2FA/Refresh تعيد قراءة وفحص live eligibility **بعد أقفال السلطة المختصة وقبل إصدار الرموز**، وليس فقط user.status أو الشركة دون العضوية. لاحظ القفل المطلوب للصفوف المرجعية وفق التصميم وتوافق التزامن؛ لا يُقبل اختبار يغيّر `status` مباشرة بدل الكاتب الحقيقي كدليل اكتمال الكاتب.
- [ ] **G01.05 — Unit/HTTP negative:** اختبر تعليق الشركة وتعطيل العضوية عبر مسارات الإنتاج؛ تأكد من منع Login/2FA/Refresh غير المخوّل وعدم تأثر شركات/جلسات أخرى مسموحة أو Mobile. اختبر عضوًا متعدد الشركات والحدود التي يحددها العقد.
- [ ] **G01.06 — MariaDB true races:** لكل production writer اختبر: (A) التعطيل يلتزم أولًا ثم محاولة الدخول/التحديث، (B) Login/Refresh/2FA تنشئ عائلة أولًا ثم التعطيل يلتزم ويبطلها. احفظ barriers فعلية، PIDs/connection IDs، فائز القفل، response لكل طلب، SQL للتحقق من session revocation وtokens والعضوية. **لا قبول لـC05/C06 باستخدام تغيير حالة مباشر**.
- [ ] **G01.07 — إثبات P1:** فحص SQL بعد كل ترتيب يشترط «لا تتبقى عائلة غير مؤهلة ولا successor صالح»، مع رفض false-positive verifier؛ إن تعذر إثبات أحد المسارين `OPEN`, STOP.

## G02 — P2: تصحيح ترتيب الأقفال في Session DELETE والعمليات المرتبطة

- [ ] **G02.01 — Inventory:** افحص `AuthService::revokeSession` ومسار `DELETE /api/v1/auth/sessions/{id}` وID القديم/الرقمي/UUID وعلاقة tokenable وlegacy. أثبت موضع `Token → Session` المخالف في النسخة الحالية لا في SHA التاريخي فقط.
- [ ] **G02.02 — تنفيذ قفل صارم:** candidate lookup بدون Token row lock؛ داخل Transaction: **User → Session(s) → Token(s)** بترتيب حتمي. تحقّق من ملكية مستخدم الطلب والمعرّف بعد الأقفال، وامنع حذف نصف العائلة أو cross-user revocation. لا تطبق D09 full listing/alias migration المؤجلة لـSlice 06 من تلقائك.
- [ ] **G02.03 — Contract/legacy tests:** اختبر DELETE family UUID أو معرّف قديم فقط وفق المتاح المصرّح به، 204 على النجاح، أخطاء للمجهول ولغير المملوك، وعدم العبث بLegacy familyless. ارفض family-operation غير مدعوم بشكل آمن بدل تنفيذ خاطئ.
- [ ] **G02.04 — MariaDB DELETE vs Refresh:** اختبر **ترتيبين**: حذف يلتزم أولًا => لا successor؛ Refresh يلتزم أولًا => DELETE المرخّص يبطل الزوج الجديد بعد الأقفال إذا حقق إثبات الهوية المطلوب؛ فسّر حالة الـstale proof إن وجدت. تحقّق SQL لا توكنات family نشطة بعد 204 ناجح.

## G03 — P2: Refresh وD08 Post-lock revalidation / expiry

- [ ] **G03.01 — Snapshot timing:** راجع التقاط `now` قبل انتظار Token locks في `refresh` و`logoutWithRefreshToken`؛ احسب الوقت **عند اتخاذ قرار الأمان تحت الأقفال**. اختبر انتهاء access/refresh/family أثناء انتظار القفل، بما فيها الحدود الدقيقة.
- [ ] **G03.02 — Refresh recheck:** بعد User → Session → Tokens، أعد التحقق من **plain token hash/secret، tokenable morph type الفعلي، user ownership، exact abilities `['admin:refresh']`، expiry، family UUID/session_id، current token ID pointers، generation، revoked_at، user/company/membership live eligibility، feature-flag classification**. Preliminary lookup candidate-only. `401 INVALID_TOKEN` للمستهلك/غير المتوافق؛ expired/revoked `401 SESSION_EXPIRED`، inactive `403 USER_INACTIVE` وفق العقد. راجع lock timeout/deadlock لعدم تزوير `409`.
- [ ] **G03.03 — D08 proof recheck:** نفّذ نفس التحقق على Refresh proof لـexpired-access Logout بعد انتظار الأقفال، مع رفض bare ID وtoken foreign/expired/consumed. في Logout bearer افحص التوكن الحالي/الملكية/الجيل تحت القفل قبل 204، لا يكفي `$request->user()` أو `currentAccessToken()` الذي جرى تحديده مسبقًا.
- [ ] **G03.04 — Rotation bounds/rollback:** ثبت العائلة UUID/alias والحد المطلق 24h أو 7d؛ Access ≤15m & family end؛ Refresh ≤ family end؛ generation increment مرة واحدة. اختبر فشل المعاملة **بعد حذف الزوج القديم، بعد إنشاء access، بعد تحديث pointers، وقبل commit**، لا تضف public/test-only bypass قابلًا للوصول في الإنتاج. تحقق أثر الحقن على كل صف فعلي.
- [ ] **G03.05 — Forced race/expiry:** حواجز انتظار حقيقية مع expiration/status/token mutation خلال الانتظار ثم استئناف production `refresh`/D08، مع فحص النتائج SQL والـHTTP. فحوص arithmetic أو `Clock::freeze` وحدها ليست دليل InnoDB race.

## G04 — P2: Legacy classification ومنع family-orphan downgrade

- [ ] **G04.01 — تصنيف صريح:** ميّز `true eligible familyless legacy Admin refresh token` من `family-linked valid` ومن `family-linked invalid/orphan/admin:refresh session_id null`. لا يُحوَّل فشل family validation إلى legacy، ولا يكفي `session_id == null` للقبول. اربط شروط legacy بآلية issuance/metadata والعقد المعتمد؛ إن لم يمكن إثبات أهلية token فليُرفض بشكل آمن.
- [ ] **G04.02 — عدم امتيازات إضافية:** لا `admin:refresh` يتلقى wildcard familyless pair بعد flag rollback أو session_id loss؛ لا تجعل `tokenCan()` وحدها إثبات exact abilities. `issueTokens()` القديمة تظل كما هي للمسارات المصرح بها فقط؛ لا تغيير global Sanctum.
- [ ] **G04.03 — Contract regression:** اختبر legacy Admin الحقيقي المقبول في Compatibility Mode، family linked مع flag true/false، orphan exact `admin:refresh`, wildcard, mixed, access-only, foreign morph type, consumed وreplayed؛ تحقق من response الفعلي (لا تصف الاختبار 200 بأنه 401). لا تجرِ cutover أو تغييرات Dashboard/Flutter.

## G05 — P2: Logout وFCM — الإثبات أولًا، الإبطال ذريًا

- [ ] **G05.01 — Auth mode:** افحص Route `POST /auth/logout` خارج `auth:sanctum` وطريقتَي Bearer وbody `{refreshToken}`؛ لا تسمح anonymous session-ID delete أو user spoof أو false `204`. التحقق من Auth proof يجب أن يسبق أي side effect.
- [ ] **G05.02 — Revoke first safely:** داخل معاملة متناسقة تحت User→Session→Tokens: revalidate proof/ownership/generation، mark revoked، invalidate both active credentials، commit. لا تنفذ device unregister **قبل** تحقق D08 أو bearer proof. لا تمكّن فشل cleanup من إلغاء revocation.
- [ ] **G05.03 — Device cleanup:** افحص آلية `revokeLoginDevice`, `RegistersDeviceTokens`, `DeviceTokenService` الفعلية وما إذا كانت محلية DB أو external FCM. حدد آلية post-commit أو بديل معتمد لا يفقد التنظيف، وتسجيل فشل محدود دون أسرار، آلية Retry مثبتة أو manual recovery موثق. لا تخترع queue؛ لا تبتلع الاستثناء وتدّعي نجاح cleanup.
- [ ] **G05.04 — Tests:** missing/invalid/bare proof, foreign ownership, expired access + valid current refresh, stale refresh, failed cleanup and device isolation, logout vs refresh **بالترتيبين** بما فيه stale proof يعيد 401 بشكل صحيح؛ أي successful 204 يعني صفر successors صالحة.

## G06 — P2: Mobile → Admin credential synchronization & password writers

- [ ] **G06.01 — Connections/writers:** افحص `CredentialSyncService::pullFromMobile` و`pushToMobile` و`IdentityMapService`، وMobile Admin peer mapper، كل connection وتاريخ cache/model preload وعمليات كتابة passwords/tokens. حدد القفل للـpeers ومخاطر deadlock/cross-connection، وميز DB writes من external side effects.
- [ ] **G06.02 — Fresh credential under lock:** لا تنسخ hash من Mobile model محمّل **قبل** انتظار User lock. أعد تحميل آخر مصدر معتمد تحت الحماية المطلوبة، وأعد تحقق أن credential version لم يتغير أثناء السباق. حدد سياسة التعارض المعتمدة، لا تخترع last-write-wins غير مصرح به أو تنسخ قيمة قديمة.
- [ ] **G06.03 — Atomic affected Admin revocation:** احصر عائلات Admin المتأثرة، lock User→Sessions→Tokens بعد ضمان عدم وجود reverse locks مع peer writers، أبطل المناسب قبل commit؛ حافظ على الرموز وسياسات مصادقة Flutter/Cashier/BranchManager. لا تفترض transaction واحدة تغطي اتصالين مختلفين.
- [ ] **G06.04 — Real tests:** Mobile pull vs Admin Refresh، Mobile pull vs Admin Login، Mobile pull vs 2FA completion، Admin change/reset vs pull in both commit orders، same-user peer race. افحص password hash النهائي والمصدر ورموز Mobile وAdmin token morph من DB. توقف عند أي تصميم cross-connection يحتاج تصريحًا.
- [ ] **G06.05 — Reset record locks:** راجع `AuthService::resetPassword`, `forgotPassword`, `resendForgotPassword` و`password_reset_tokens` كمسارات كتابة حقيقية. Lookup أولي مرشح فقط؛ اقفل User أولًا ثم Reset record في ترتيب موحد لا يعكس أقفال Forgot/Resend، تحقق من **نفس** secret داخل المعاملة، ثم احذف السجل/النسخة التي تحققت منها فقط (لا تحذف إصدارًا أحدث). لا تفترض uniqueness لصف غير مثبت في schema.
- [ ] **G06.06 — Reset expiry/replay:** عبّر عن الانتهاء بصراحة `now >= created_at + 60 minutes`؛ اختبر 59:59، 60:00، 60:01، reset متنافس بنفس السر، وReset مقابل Forgot/Resend في كلا الترتيبين إذا اختلفت النتيجة؛ تحقق أن تغيير كلمة المرور وإبطال العائلات واستهلاك الرمز يعملون كوحدة متماسكة دون مساس بالرمز الأحدث.

## G07 — P3: إصلاح الاختبارات المضللة وصرامة متحققات SQL

- [ ] **G07.01 — 2FA stale-challenge test:** أصلح اختبار `AdminCredentialRevocationTest` الذي يقبل أي `AsabException` مع OTP ثابت؛ أثبت أولًا أن **نفس OTP صالح** قبل الإبطال لحالة مضبوطة، ثم بعد password reset/deactivation رفض **بالسبب الأمني المحدد/status/code** مع صفر عائلات/توكنات؛ غطِّ Direct password+code، RememberMe server-bound challenge، واستهلاك challenge مرة واحدة. لا تعتبر OTP خاطئ نجاحًا للاختبار.
- [ ] **G07.02 — Tokenable morph truth:** أصلح `verify_c15_db_state.php`, `verify_cmob_db_state.php` وكل verifiers التي تفترض اسم class كامل؛ استخرج `getMorphClass()`/morph map الحقيقي (مثل القيمة `accountant` بحسب AUDIT) واستخدم filters تطابق DB. اختبر verifier باختبار **negative control** يترك توكنًا غير مصرح به ليؤدي إلى FAIL حقيقي، ثم استرجع حالة معمل نظيفة.
- [ ] **G07.03 — C05/C06 verifier:** لا تقبل `200 OR 403/401` بشكل فضفاض. احسب المتوقع بحسب ترتيب المعاملة، وتحقق من حالة العائلة، الـgeneration، token pointers، كل token owner/abilities، صلاحية العضوية/الشركة ووجود أو عدم وجود أي successor. لا تعتمد على Connection IDs وحدها لإثبات التزامن.
- [ ] **G07.04 — Manifest/Matrix builder:** احذف إسنادات `PASS` المباشرة في `update_requirements_matrix.php` أو أي runner. اجعل الأدلة المطلوبة وexit code/status/SQL checks تشترط success، وقارن مع allowlist لكل حالة، وfail-closed on missing/stale/corrupt evidence. لا تخفِ failed run بإعادة تسمية files أو حذف logs.
- [ ] **G07.05 — Negative controls:** عطّل شرطًا واحدًا في اختبار/fixture معملية غير إنتاجية أو احقن حالة DB مخالفة للتأكد من أن كل verifier يعلن FAIL، ثم نظّف الأثر عبر fixture معزولة وأعد فحص الحالة الأصلية. لا تلمس production DB أو تقلل صرامة التحقق.

## G08 — C01–C20 + الحالات الإضافية: إغلاق الاختبارات حـالـةً حـالـةً وبالترتيب

**بروتوكول لكل `Cxx`:** راجع approved expected order A/B، ثم شغّل فقط الترتيب غير المثبت أو الاختبار الناقص. يلزم workerان مستقلان، **production service/controller/writer الحقيقي**، حواجز تحدد pre-read وlocking وcommit بالضبط، PIDs وDB connection IDs، response+exit لكل worker، وSQL snapshot مستقل مع verifier حساس للفشل، وRun ID+binaries/hashes. لا يُحتسب تغيير ترتيب تشغيل عمليات PowerShell فقط اختبارًا لترتيبي commit. إن كانت الحالة boundary/rollback غير تنافسية، صنفها functional/injection بدقة ولا تدّعي أنها race حقيقي. **لا تنتقل من Cxx إلى Cxx+1 إلا بعد PASS Cxx بجميع شروطه.**

- [ ] **G08.C01** Refresh vs Refresh؛ لرمز واحد ينتج زوج واحد، الجيل يزيد مرة واحدة، الفائز 200 والخاسر 401 `INVALID_TOKEN`؛ اختبر تبادل الفائز واستعمل locks حقيقية.
- [ ] **G08.C02** Refresh vs standard Logout؛ Logout أولًا يمنع refresh، Refresh أولًا ثم logout ناجح يبطل successor، وstale logout proof قد يرجع 401 وفق العقد وليس 204 زائفًا.
- [ ] **G08.C03** Refresh vs Password Change؛ في الترتيبين لا survivor بعد invalidation، recheck password داخل User lock.
- [ ] **G08.C04** Refresh vs Password Reset؛ في الترتيبين consume exact reset record وتمنع الجلسة المستقبلية بعد commit.
- [ ] **G08.C05** Refresh vs actual User Deactivation production writer؛ في الترتيبين وحالة tokens، لا direct SQL status worker.
- [ ] **G08.C06** Refresh vs actual Company Suspension/Membership disable production writers؛ في الترتيبين، لا تغيير state صناعي؛ راع multi-company live eligibility.
- [ ] **G08.C07** Refresh vs absolute family expiry؛ قبل/عند/بعد وقت النهاية، بما فيها انتهاء الصلاحية خلال انتظار القفل؛ لا sliding lifetime.
- [ ] **G08.C08** Transaction rollback في **أربع** نقاط بعد delete old pair، بعد create access، بعد update pointers، قبل commit؛ تحقق DB قبل/بعد والفشل الصحيح، ولا تترك orphan أو generation متقدمًا.
- [ ] **G08.C09** Refresh لعائلتين مستقلتين (نفس المستخدم وأجهزة مختلفة)؛ الاثنتان تصحان دون فساد وبترتيبين إن كان له أثر.
- [ ] **G08.C10** Refresh vs Expired-Access Logout؛ proof قبل وبعد rotation، stale proof 401 (ولا تدعي Logout ناجحًا)، 204 إن نجح يبطل كل الزوج.
- [ ] **G08.C11** Deadlock/Lock timeout فعلي ومحكوم أو fault injection موثوق لخطأ DB، تحقق من عدم 409 كاذب وعدم partial write، سجل SQLSTATE/rollback؛ `SHOW PROCESSLIST` خالٍ وحده لا يثبت الاختبار.
- [ ] **G08.C12** Legacy Admin familyless الصحيح وMobile/Cashier/BranchManager boundaries؛ تصنيف/abilities/TTL بلا تغييرات؛ `200` للlegacy المؤهل وليس `401` عامًا، والـMobile لا يأخذ family semantics.
- [ ] **G08.C13** Refresh vs **existing production DELETE**؛ كلا الترتيبين، auth ownership وalias/UUID ضمن المعتمد، بلا نصف عائلة.
- [ ] **G08.C14** Login vs Password Change؛ invalidation أولًا يرفض credentials قديمة؛ login أولًا تلغى عائلته بعد change.
- [ ] **G08.C15** Login vs Password Reset؛ الحالتان وSQL morph الصحيح وعدم survivor، لا verifier يقبل صفرًا كاذبًا.
- [ ] **G08.C16** 2FA challenge completion vs Reset؛ OTP صالح للمعمل قبل الإبطال، يفشل بعده لسبب credential invalidation محدد لا OTP ثابت خاطئ.
- [ ] **G08.C17** 2FA completion vs real User Deactivation/Company Suspension/Membership invalidation؛ المسارات الإنتاجية والنتيجة الأمنية الدقيقة.
- [ ] **G08.C18** Direct Password + Code vs credential/account invalidation؛ صحة الكود/السِرّ قبل invalidation ورفض آمن بعده تحت الأقفال.
- [ ] **G08.C19** Password Change vs Password Change؛ old password واحدة، الأولى 204 والثانية 422 بعد إعادة الفحص، ويثبت final password للفائز فقط.
- [ ] **G08.C20** Reset vs Reset بنفس الرمز؛ استهلاك مرة واحدة، لا تغيير ثانٍ، لا حذف جديد بصورة غير مقصودة.
- [ ] **G08.CMOB** Mobile→Admin sync vs Refresh **وLogin و2FA** وترتيبا السباق حيث يلزمان؛ افحص hash والرموز Mobile/Admin وأهلية الإصدار.
- [ ] **G08.CRES** Reset vs Forgot/Resend؛ reset يُمسك exact token record، لا يحذف الرمز الجديد، وفحص boundary `now >= created_at + 60m` منتهيًا.
- [ ] **G08.CFLG** Flag true→false: family-linked لا ينقلب legacy؛ فرق بوضوح بين استمرار معالج family أو الرفض المعتمد؛ سجّل **الاستجابة الفعلية** لا تزعم 401 والاختبار يتوقع 200.
- [ ] **G08.CLOST** Refresh commit + lost response: إعادة القديم 401، لا pair جديد أو replay grace؛ ambiguous 5xx لا يعني أن العملية rollback تلقائيًا.
- [ ] **G08.CFCM** Cleanup failure: atomic revoke 204، فشل cleanup موثق مع آلية إعادة محاولة فعلية أو manual fallback، لا تسريب أو استثناء يقطع الإبطال.

## G09 — Contract, privacy, compatibility and remaining writers

- [ ] **G09.01 — API contract:** تحقق من request/response لLogin/2FA/Refresh/Logout/Reset/Change/DELETE مع الأسماء والأغلفة والأكواد المعتمدة: `{refreshToken}`، additive `sessionId/expiresAt/sessionExpiresAt`، `expiresIn`، 204/401/403/422/409 بشروطها. لا حقول Dashboard إجبارية جديدة ولا auth endpoint anonymous family delete.
- [ ] **G09.02 — Guest/Access/Refresh separation:** Refresh exact ability وtokenable، Access bearer proof تحت القفل؛ general restriction على كل Admin route **DEFERRED TO SLICE 07**، لا تدع أن Slice 04/05 تنفذه.
- [ ] **G09.03 — Other writers:** تحقق من كل User status/credential/account membership writer في G00؛ لا تنسَ membership and multi-company scope؛ لا تدوّن `ALL WRITERS COMPLETE` إذا مسار غير مُنسق.
- [ ] **G09.04 — Legacy/clients:** تأكد من سلوك compatibility flag default false، login القديم الصحيح، SSO/Invitations source behavior دون إصلاحات منفصلة، Dashboard contract read-only، Flutter/BranchManager/Cashier regression، عدم تعديل finance/idempotency. D09 full session enumeration = Slice 06؛ Strict Guard = Slice 07؛ S1-13 cross-tab/storage release gate.
- [ ] **G09.05 — Production test hook:** `invokeTestHook` الخاص testing فقط يبقى غير متاح من HTTP أو الإنتاج؛ لا public security bypass، ولا تغيّر production behavior لأجل تسهيل إثبات الاختبار.

## G10 — الاختبارات النهائية وربط الأدلة بالكود النهائي

- [ ] **G10.01 — Freeze:** حدد النسخة النهائية بعد كل الإصلاحات، استخرج full SHA-256 source+test inventory، snapshot Git status/diff، ارفض أي ملف production غير مصرح به أو تعديل خارجي.
- [ ] **G10.02 — SQLite:** شغّل **مرة واحدة على final state** المجموعات التسع الأصلية والمجموعات الثلاث الجديدة وكل الاختبارات التي أضيفت للعيوب، وسجّل العدد الفعلي/Assertions/Skip/Failures/Exit/Run ID/JUnit/stdout/stderr/start-end hashes. لا تعتمد 126/612 التاريخية.
- [ ] **G10.03 — MariaDB:** شغّل نفس النطاق على المعمل الحقيقي، مع حفظ مستقل للـ12 suites أو العدد النهائي الفعلي، لا تعتمد على manifest قديم ولا جمع نتائج من مصادر مختلفة بلا timestamps/hashes موثقة. لا تستبدل C-races بنتيجة PHPUnit.
- [ ] **G10.04 — Drift control:** أي تغيير بعد اختبار يستوجب تحديث Run provenance وإعادة الاختبار المتأثر وما أصبح غير صالح من regression؛ لا حاجة إلى إعادة كافة الاختبارات بسبب comments أو redaction فقط مع توثيق عدم تغيير السلوك.
- [ ] **G10.05 — Final matrix validator:** تحقق آليًا من كل ID، actual exit codes/JUnit وSQL results، hashes وtimestamps، Order A/B لكل mandatory race، test fidelity، approval scope. نقص بند واحد => `NOT READY FOR AUDIT`، حتى لو كل suite الأخرى خضراء.

## G11 — تنقيح الحزمة ومراجعة الأمن النهائي

- [ ] **G11.01 — Raw evidence محفوظ:** احفظ الأدلة الأصلية في مكان محمي خارج repo؛ أنشئ reviewer package منفصلة، ولا تنسخ credentials raw.
- [ ] **G11.02 — Dynamic redaction:** عالج قيم الاختبار الفعلية في `c03_fixture.json`, `c04_fixture.json`، و**كل** JSON/log/SQL snapshot/PHP script/manifests/Cache keys، خصوصًا passwords, reset tokens, access/refresh secrets, 2FA challenge/TOTP, APP_KEY/DB_PASSWORD, FCM. Regex keywords فقط غير كافٍ؛ استخدم inventory للقيم الفعلية والبحث عنها بعد التنقيح، دون كشفها في التقرير. لا تدّع أنها بيانات إنتاجية دون دليل.
- [ ] **G11.03 — Checksums:** بعد التنقيح أنشئ checksum index من **محتوى الحزمة النهائي**، تحقق وجود كل ملف وتطابق SHA-256 للكل، واربط source hashes بالنسخة التي اختُبرت. مطابقة 149 checksum القديمة لا تغني عن النسخة الجديدة.
- [ ] **G11.04 — Actual-diff security review:** راجع كل diff خاصة AuthService, CredentialSyncService, AuthController, routes, controllers المعتمدة, tests, verifiers والمهاجرات. أثبت عدم Reverse lock، legacy downgrade، side-effect قبل proof، false success test، cross-device revocation، data-leak أو تغيير عام لـSanctum أو Mobile.

## G12 — بوابة التسليم النهائية (ليست قبولًا مستقلًا)

- [ ] **G12.01 — Audit findings ledger:** جدول منفصل **لكل** ملاحظة التدقيق الأخير (P1, P2 DELETE, P2 Post-lock, P2 Legacy, P2 Logout/FCM, P2 Mobile stale, P2 Concurrency/verifiers, P3 2FA false-positive, P3 credentials redaction) يتضمن `RESOLVED/OPEN/BLOCKED`, exact file+line, test, Run IDs, SQL outcome, source hash. لا تُخفِ العيوب خلف PASS عام.
- [ ] **G12.02 — Exhaustive reconciliation:** قارِن checklist النهائية بالتصميم والعقد وخطة v1.2 **بندًا بندًا**، وعدد IDs عند البداية والنهاية ومصدر التغييرات، وتأكد من كل C01–C20 والإضافات وWriters. لا تحذف أو تدمج ID لإظهار الاكتمال.
- [ ] **G12.03 — Report factual integrity:** صرّح بما اختُبر فعلًا، SQLite مقابل MariaDB، exact test counts، ما ثبت بعمليات production حقيقية مقابل PHPunit، كل ترتيب race، والنتيجة الفعلية لكل worker، وفارق `self-verified` و`independently audited`. لا تنسب نتائج الاختبارات لإصدار لم تختبره.
- [ ] **G12.04 — Deliver artifacts:** سَلّم مصفوفة JSON كاملة، ملف gate validator وطريقة تشغيله، writer inventory+lock map+approval decisions، diff/untracked manifest، تقارير الاختبارات وJUnit/Run IDs، concurrency harness/verifiers/negative controls، raw evidence location (محمي)، sanitized reviewer package/checksums، status/HEAD/hashes والقيود والمخاطر المؤجلة.
- [ ] **G12.05 — Final decision:** إذا اجتازت **كل البنود المطلوبة بالتصريح** وظهر validator PASS وبدون أي blocker أمني/موافقة: اكتب فقط `SLICE 04 + 05 SELF-VERIFIED — READY FOR TARGETED INDEPENDENT AUDIT`. إذا بقي بند واحد: `PARTIAL/BLOCKED — NOT READY FOR AUDIT` مع IDs محددة. **لا تعتبر تقريرك قبولًا مستقلًا**. سلّم إلى AUDIT WORK للمراجعة read-only ثم محمود للقبول الفني النهائي؛ لا تصدر تفويضًا جديدًا لـGit أو النشر.

**ابدأ بـG00.01 الآن. لا تبدأ G00.02 قبل إغلاق G00.01 بالدليل، وهكذا حتى G12.05. لا تقفز إلى اختبار شامل أو تقرير COMPLETE قبل اكتمال المسار. إذا ظهر حاجز تصريح، توقف فورًا وقدّم طلب تصريح محدد، ولا تنفذ إصلاحًا مشتركًا غير مأذون به. توقف بعد التسليم.**
