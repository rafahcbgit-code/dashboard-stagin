# تقرير المشاكل والحلول في محادثات مشروع Chatberry

**تاريخ التقرير:** 2026-07-29  
**المشروع:** `C:\Users\DELL\chatberry-dashbaord`  
**النطاق:** مراجعة سجل المهام والمحادثات الظاهر في Codex، مع التركيز على المحادثات التي تحتوي على مشكلة، تشخيص، حل، تعديل، أو إجراء تشغيلي. تمت مراجعة تفاصيل المحادثات البرمجية المرتبطة بالمشروع، والمحادثات العامة المرتبطة بـ Laravel وMeta وWhatsApp وMCP وOllama وSMTP وقواعد البيانات.

> ملاحظة النطاق: التقرير مبني على المحادثات الظاهرة في سجل التطبيق وقت إعداد التقرير. أي محادثة مؤرشفة أو غير ظاهرة في السجل الحالي قد لا تكون مشمولة.

## 1. طريقة التصنيف

| الرمز | المعنى |
|---|---|
| **لوكال** | التعديل أو الإصلاح يتم في الكود/الإعدادات/قاعدة البيانات المحلية فقط. |
| **سيرفر** | المشكلة في الإنتاج أو تحتاج أوامر على خادم الإنتاج أو إعدادًا لدى مزود خارجي. |
| **مشترك** | يبدأ الإصلاح في الكود المحلي ثم يحتاج نشرًا، migrations، cache/queue restart، أو إعدادًا خارجيًا. |
| **مكتمل** | تم تنفيذ التعديل والتحقق منه بحسب المحادثة. |
| **مشخّص** | تم تحديد السبب، لكن الإجراء الخارجي أو الإصلاح النهائي لم يكتمل. |
| **جزئي/متابعة** | يوجد حل أو حماية، لكن توجد فجوة اختبار أو خطوة نشر أو قرار خارجي. |
| **معلومة** | ليست عطلًا برمجيًا، بل توضيح لسلوك أو قيد رسمي. |

## 2. الملخص التنفيذي

أكثر المشاكل تكرارًا كانت في الطبقات التالية:

1. **الإنتاج والصلاحيات:** أخطاء صلاحيات Meta، صلاحيات ملفات Laravel، صلاحيات اللوج، وتطابق إعدادات queue/cache.
2. **WhatsApp/Meta:** `#200`, حدود الإرسال `#130429`, فشل التوكن أو عدم امتلاك WABA، وقيود حذف الرسائل عبر API.
3. **الحملات:** فقدان أزرار القالب، تعديل جدولة الحملات، rate limit وtimeouts.
4. **الأداء:** N+1 queries، استعلامات المحادثات، عدادات الإشعارات، واستخدام الموارد.
5. **البيئة المحلية:** Ollama، Composer/vendor، Vite، واتصال MySQL الاختباري.
6. **التكاملات:** MCP/OAuth، QIPS/n8n، SMTP/Hostinger، وEmbedded Signup/Coexistence.

## 3. سجل المشاكل والحلول بالتفصيل

### 3.1 نظام Role & Permissions في لوحة الإدارة لا يطبق الصلاحيات

- **مصدر المحادثة:** `019fa802-ddbf-7302-a5d0-d71ae7f44b4a` — تحقق من صلاحيات Role.
- **التصنيف:** RBAC / أمن وتفويض.
- **النطاق:** **مشترك**؛ منطق الكود محلي، ثم migration ونشر على السيرفر.
- **الحالة:** **مكتمل برمجيًا، مع خطوة نشر مطلوبة**.
- **المشكلة:** كانت الصلاحيات تحفظ في `role_permissions`، لكن القائمة والصفحات والمسارات الإدارية لا تقرأها فعليًا. المستخدمون يرون نفس الأقسام، كما أن حماية المسارات كانت تعتمد عمليًا على تسجيل الدخول فقط. ظهر أيضًا أن مستخدمًا بدور admin لم ير أقسام الإدارة بسبب عدم اتساق منطق العرض.
- **السبب الجذري:** لا يوجد مصدر موحد للصلاحيات يربط `module/action` بالمسارات والواجهة، والقائمة كانت ثابتة. كما كانت علاقة `Role::permissions()` غير صحيحة.
- **الحل المنفذ:**
  - إنشاء كتالوج مركزي في `config/admin_permissions.php`.
  - إضافة Middleware `AuthorizeAdminPermission` لمنع الوصول المباشر للمسارات غير المسموحة وإرجاع `403`.
  - تمرير الصلاحيات إلى Inertia واستخدام `useAdminPermissions` لإخفاء القوائم والأزرار.
  - إبقاء الدور المدمج `admin` بصلاحية كاملة.
  - منع مستخدم غير admin من تعديل أو منح صلاحيات أعلى من صلاحياته أو تعديل دور admin.
  - إضافة migration لمزامنة وحدات جدول `modules` واختبارات `AdminPermissionTest`.
- **الملفات الأساسية:** `config/admin_permissions.php`, `app/Http/Middleware/AuthorizeAdminPermission.php`, `app/Services/RoleService.php`, `app/Services/UserService.php`, `app/Models/Role.php`, مكونات Admin Vue، و`tests/Feature/AdminPermissionTest.php`.
- **التحقق:** فحص PHP وبناء Vue نجحا. اختبار Feature لم يكتمل بسبب `Access denied for user 'root'@'localhost'` في قاعدة الاختبار المحلية.
- **إجراء السيرفر:** نشر التعديلات وتشغيل `php artisan migrate` ثم `php artisan optimize:clear` وإعادة بناء الواجهة عند الحاجة.

### 3.2 خطأ `Invalid permission` عند حفظ الدور

- **مصدر المحادثة:** ضمن المهمة نفسها، ثم متابعة `RoleService.php:90`.
- **التصنيف:** RBAC / توافق بيانات.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل**.
- **المشكلة:** الواجهة كانت تعرض صفوفًا قديمة من جدول `modules`، بينما الخادم يتحقق من كتالوج صلاحيات مركزي مختلف؛ لذلك تظهر صلاحيات للمستخدم ثم يرفضها الخادم عند الحفظ.
- **الحل:** جعل الواجهة والخادم يستخدمان نفس `config('admin_permissions.modules')` بدل `Module::all()`، مع migration لمزامنة الجدول.
- **الملف:** `app/Services/RoleService.php`.
- **إجراء السيرفر:** تطبيق migration ومسح الكاش ثم إعادة فتح نموذج الدور.

### 3.3 خطأ تسجيل الدخول في الإنتاج و`ERR_CONNECTION_RESET`

- **مصدر المحادثة:** متابعة داخل مهمة Role، مع رسالة Chrome extension و`/admin/organizations`.
- **التصنيف:** إنتاج / شبكة / جلسات.
- **النطاق:** **مشترك، والجزء الحاسم على السيرفر**.
- **الحالة:** **جزئي/مشخّص**.
- **المشكلة:** رسائل `chrome-extension://...` و`ERR_CONNECTION_RESET` و`AxiosError: Network Error` أثناء الوصول إلى `/admin/organizations`.
- **التمييز المهم:** رسالة Chrome extension من إضافة متصفح وليست من التطبيق. أما `ERR_CONNECTION_RESET` فهي انقطاع من الخادم أو Nginx أو PHP-FPM أو الشبكة أثناء الطلب.
- **الحل المنفذ:** تحسين `AuthController::logout()` لتسجيل الخروج من حارسي `user` و`admin`، إبطال الجلسة، مسح بيانات المنظمة وTFA، وتجديد CSRF token.
- **الملف:** `app/Http/Controllers/AuthController.php`.
- **التحقق:** طلب غير مصادق عليه إلى `/admin/organizations` أعاد تحويلًا طبيعيًا إلى `/login`.
- **المطلوب على السيرفر:** فحص Nginx/PHP-FPM و`storage/logs/laravel.log` وقت الخطأ، ثم `php artisan optimize:clear` وإعادة تشغيل PHP-FPM/الخدمة عند نشر التعديل. تعطيل إضافة Chrome فقط لأغراض الاختبار.

### 3.4 صلاحية `Login as` غير مستقلة

- **مصدر المحادثة:** ضمن مهمة Role.
- **التصنيف:** RBAC / Admin UX.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا**.
- **المشكلة:** كان الإجراء مربوطًا بصلاحية تعديل المنظمات، ولا يمكن التحكم به مستقلًا من شاشة الصلاحيات.
- **الحل:** إضافة `organizations.login_as`، ربط المسار بها، إخفاء زر `Login as` عند عدم امتلاكها، وإضافة migration لتحديث سجل `modules`.
- **الملفات:** `config/admin_permissions.php`, `resources/js/Components/Tables/OrganizationTable.vue`, `database/migrations/2026_07_28_000002_add_login_as_organization_permission.php`.
- **المطلوب:** تشغيل migration على السيرفر ومسح cache/build إن كان الإصدار منشورًا.

### 3.5 خطأ Meta `#200` أثناء إنشاء القوالب

- **مصدر المحادثة:** `019f7a84-264b-73f0-8c9a-841b5a17fd2d` — سجل نتيجة `createTemplate`.
- **التصنيف:** WhatsApp Cloud API / Meta permissions.
- **النطاق:** **سيرفر وخارجي Meta**، مع تعديل Logging محلي.
- **الحالة:** **الخطأ مشخّص؛ تعديل السجل مكتمل؛ الإجراء النهائي في Meta مطلوب**.
- **المشكلة:** `POST /{waba-id}/message_templates` يعيد `(#200) You do not have permission to access this field`.
- **السبب المرجح:** التوكن المخزن للمؤسسة لا يملك `whatsapp_business_management` على WABA المحدد، أو أن System User غير مضاف إلى WABA بصلاحية Full control. الخطأ ليس في محتوى القالب غالبًا.
- **الحل المنفذ:** إضافة تسجيل منظم لاستجابة Meta داخل `WhatsappService::createTemplate` يتضمن `organization_id`, `waba_id`, اسم القالب، النجاح ورسالة الخطأ.
- **الملف:** `app/Services/WhatsappService.php`.
- **الحل التشغيلي:** من Meta Business Settings أضف WABA إلى System User، امنحه الصلاحيات المطلوبة، أنشئ Access Token جديدًا يتضمن `whatsapp_business_management` و`whatsapp_business_messaging`، ثم استبدل التوكن في إعدادات المنظمة.
- **تحذير:** لا تُسجّل Access Token كاملًا في اللوج، ويجب تدوير أي توكن ظهر في المحادثات أو اللوجات.

### 3.6 Coexistence يفشل بصلاحيات Meta

- **مصدر المحادثة:** `019f7ed5-5392-7631-a944-173eff6ce9af` — حل خطأ صلاحيات WhatsApp Coexist.
- **التصنيف:** Embedded Signup / Coexistence / Meta authorization.
- **النطاق:** **مشترك، مع اعتماد نهائي على إعدادات Meta**.
- **الحالة:** **مكتمل من جهة التشخيص والحماية، وإعادة الربط بتوكن جديد مطلوبة**.
- **المشكلة:** في آخر مرحلة من ربط WhatsApp Coexist يظهر `#200` عند الوصول إلى WABA أو القوالب أو رقم الهاتف.
- **الأسباب المحتملة التي تم عزلها:** نقص OAuth scopes، اختلاف App ID عن `config_id`، نوع توكن غير مناسب، عدم وجود WABA ضمن `granular_scopes/target_ids`، أو عدم تعيين System User/Asset Tasks على WABA.
- **الحل المنفذ:**
  - إنشاء `MetaTokenDiagnosticsService` لفحص `is_valid`, App ID، نوع التوكن، scopes، granular scopes وWABA targets.
  - تنفيذ اختبارات آمنة لـ WABA، أرقام الهاتف، القوالب، و`subscribed_apps` قبل حفظ التوكن.
  - منع حفظ توكن لا يستطيع قراءة القوالب.
  - إيقاف تسجيل Authorization Code والتوكن الكامل.
  - إصلاح Pagination في `syncTemplates()` واستخدام `paging.next` فعليًا.
  - تعديل الواجهة حتى لا تخفي رسالة التشخيص بإعادة تحميل إضافية.
- **الملفات:** `modules/EmbeddedSignup/Services/MetaTokenDiagnosticsService.php`, `modules/EmbeddedSignup/Controllers/RegisterController.php`, مكونات Embedded Signup، واختبارات الخدمة.
- **التحقق:** فحوص PHP وVue واختبارات المحاكاة نجحت، والفحص الحي للتوكن الحالي أعاد `TOKEN_VALID` و`WABA_ACCESS_CONFIRMED`.
- **المطلوب الخارجي:** إعادة تجربة Coexistence من Meta بتوكن جديد، والتأكد من `whatsapp_business_management`, `whatsapp_business_messaging`, `business_management` وAsset Tasks. لا تعتبر العملية ناجحة قبل نجاح قراءة القوالب فعليًا.

### 3.7 فشل قراءة القوالب على بعض الحسابات فقط

- **مصدر المحادثة:** `6a5cca6c-55cc-83eb-9a77-58931180040a` — خطأ Meta `#200`.
- **التصنيف:** Meta/WABA access / دعم خارجي.
- **النطاق:** **سيرفر وخارجي Meta**.
- **الحالة:** **مشخّص/تذكرة دعم**.
- **المشكلة:** HTTP 403 و`#200` لبعض WABAs بينما حسابات أخرى تعمل عبر نفس التطبيق والكود.
- **الاستنتاج:** الاحتمال الأكبر فرق على مستوى WABA أو granular access أو Business Integration أو System User/Partner asset، وليس عطلًا عامًا في endpoint.
- **الحل المقترح:** مقارنة WABA متأثر بآخر يعمل، وإرسال تذكرة Meta تتضمن App ID وBusiness IDs وWABA/Phone IDs ووقت الطلب و`fbtrace_id`، دون إرسال التوكن.
- **المطلوب:** تنفيذ مقارنة API بالتوكن نفسه على الحسابين، وتسجيل `fbtrace_id` ونسخة Graph API، ثم متابعة Meta.

### 3.8 اسم ملف PDF يظهر `untitled` في Interactive Buttons

- **مصدر المحادثة:** `019f88d5-66fc-7413-9186-b2cc2002d363`.
- **التصنيف:** FlowBuilder / WhatsApp media payload.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل**.
- **المشكلة:** عقدة Interactive Buttons التي تحتوي PDF في Header ترسل الرابط فقط، فيظهر للمستلم `untitled` بدل الاسم الأصلي.
- **السبب:** غياب حقل `filename` في ترويسة مستند WhatsApp.
- **الحل:** تمرير اسم الملف الأصلي في `FlowExecutionService.php`، مع اختبار يثبت PDF ولا يؤثر على الصور.
- **الملفات:** `modules/FlowBuilder/Services/FlowExecutionService.php`, `tests/Unit/Services/FlowInteractiveDocumentHeaderTest.php`.
- **التحقق:** 9 اختبارات و13 assertion نجحت. التغيير يحتاج نشرًا على السيرفر لتظهر الأسماء في الرسائل الجديدة.

### 3.9 فلو FlowBuilder يستخدم معرف خطوة نصيًا مع عمود رقمي

- **مصدر المحادثة:** متابعة داخل محادثة PDF/FlowBuilder.
- **التصنيف:** FlowBuilder / Database schema / orphan execution state.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل**.
- **المشكلة:** `flow_user_data.current_step` كان `BIGINT` بينما الفلو يستخدم IDs نصية مثل `start1`. كما كان timeout يحاول إنشاء `FlowLog` لفلو محذوف.
- **الحل:** تحويل العمود إلى `VARCHAR(191)`، حماية تسجيل timeout، وتنظيف الحالة اليتيمة داخل transaction مع rollback آمن.
- **الملفات:** migration `2026_07_22_000001_change_flow_user_data_current_step_to_string.php`, `FlowExecutionService.php`.
- **التحقق:** الفلو 2522 بدأ من `start1` بنجاح، وتم تنظيف حالة الاتصال القديمة، ونجحت 9 اختبارات/13 assertion. يلزم نشر migration وإعادة تشغيل عمال الطابور.

### 3.10 بطء إنشاء قوالب Carousel عند كثرة الميديا

- **مصدر المحادثة:** `019f5c29-682a-7f00-a937-189f2c52ae2c`.
- **التصنيف:** WhatsApp templates / performance.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا، يحتاج اختبار إنتاجي حذر**.
- **المشكلة:** رفع أكثر من خمس بطاقات أو فيديوهات بالتسلسل يستغرق وقتًا طويلًا.
- **الحل:** إضافة `uploadManyToMeta()` لرفع الملفات بالتوازي مع حد آمن افتراضي 3، وربطه بإنشاء Carousel، وإضافة `META_CAROUSEL_UPLOAD_CONCURRENCY`.
- **الملفات:** `app/Services/MediaUploadService.php`, `app/Services/WhatsappService.php`, `config/services.php`.
- **التحقق:** `php -l` نجح. لم يجرِ رفع حي إلى Meta بملفات فعلية؛ لذلك يجب مراقبة rate limits بعد النشر وعدم رفع concurrency بلا قياس.

### 3.11 اسم جهة الاتصال يحفظ كـ `name` حرفيًا في `api/send/template`

- **مصدر المحادثة:** `019f5f7a-6f97-73e1-bf27-ba113e9eaeeb`.
- **التصنيف:** API / Contacts data.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل**.
- **المشكلة:** عند إنشاء contact جديد، كان الاسم الافتراضي النص الثابت `name` بدل أخذ الاسم من payload.
- **الحل:** دعم `name` الكامل، مع الإبقاء على `first_name` و`last_name` للتوافق، وتقسيم الاسم الكامل إلى الحقلين.
- **الملف:** `app/Http/Controllers/ApiController.php`.
- **مثال payload:** `name: "Ahmad Ali"` أو `first_name` و`last_name`.
- **التحقق:** فحص PHP نجح. يلزم نشر الكود والتحقق من API في الإنتاج.

### 3.12 إرسال الرسالة الصادرة يظهر مرة أخرى كرسالة واردة

- **مصدر المحادثة:** `019f750b-1f18-7ea1-9c6d-25d572c84c80` و`019f74fc-bfa2-71e1-af4c-a3c7231f9109`.
- **التصنيف:** Webhook integration / QIPS/n8n / message direction.
- **النطاق:** **سيرفر وتكامل خارجي**.
- **الحالة:** **مشخّص؛ الإصلاح النهائي خارج واجهة Chatberry**.
- **المشكلة:** الرسالة تُحفظ outbound ثم تظهر inbound مكررة.
- **السبب الجذري:** الواجهة ترسل طلبًا واحدًا فقط. بعد ذلك يطلق النظام webhook `message.sent`، وتقوم وجهة خارجية مثل QIPS/n8n بإعادة تمرير النص إلى webhook التطبيق كأنه رسالة واردة. المعرّف الوارد مثل `wamid.local.qips...` مختلف عن `wamid.HBg...`، لذلك لا تمنعه حماية التكرار المبنية على `wam_id`.
- **الحل الصحيح:** تعديل QIPS/n8n لعدم إعادة نشر أحداث `message.sent` إلى webhook WhatsApp كـ`messages`. إن كانت المزامنة مطلوبة، تحفظ كـoutbound وبالمعرّف الأصلي. لا تستخدم تطابق النص لحذف التكرار لأن العميل قد يرسل النص نفسه بشكل صحيح.
- **الملفات التي تم تحليلها:** `ChatForm.vue`, `ChatService.php`, `WhatsappService.php`, `ProcessWebhookJob.php`.
- **المطلوب على السيرفر:** تعطيل أو تعديل endpoint الخارجي المسبب، ثم مراقبة webhook logs و`wam_id`.

### 3.13 الحملات: أزرار القالب لا تظهر في Preview

- **مصدر المحادثة:** `019f75c6-a06c-7b50-903c-79ea21531d61`.
- **التصنيف:** Campaign data integrity / Preview.
- **النطاق:** **سيرفر لبيانات الحملة، ومشترك لتحصين الكود**.
- **الحالة:** **مشخّص مع حل وقائي مقترح**.
- **المشكلة:** الحملة `76543` لا تعرض أزرارًا بينما `1923` تعرضها، رغم استخدام القالب `1041` نفسه الذي يحتوي 4 أزرار.
- **السبب:** `campaigns.metadata` للحملة `76543` يحتوي `buttons: []`، بينما الحملة الأخرى تحفظ الأزرار كاملة. صفحة Preview تعتمد نسخة الحملة لا القالب مباشرة.
- **الحل الموصى به:** الخادم يجلب أزرار القالب المعتمد ويبني نسخة الحملة، ويدمج فقط متغيرات الواجهة، ويرفض أو يصلح `buttons: []`. تعطيل إنشاء الحملة حتى اكتمال تحميل القالب، وإضافة اختبار.
- **الإجراء العاجل:** إصلاح metadata للحملات المجدولة قبل بدء الإرسال، مع فحص عدم وجود سجلات إرسال قبل التعديل.

### 3.14 تعديل وقت الحملة المجدولة

- **مصدر المحادثة:** `019f75a2-ec13-7ff0-a172-ad5dff3a536d`.
- **التصنيف:** Campaign scheduling / UX.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا**.
- **الحل:** زر يظهر فقط للحالة `scheduled`، تحقق من موعد مستقبلي، منع التعديل بعد بدء الحملة أو لمنظمة أخرى، وحفظ التوقيت حسب timezone المنظمة.
- **الملفات:** `CampaignController.php`, `Campaign/View.vue`, `routes/web.php`, `CampaignScheduleUpdateTest.php`.
- **التحقق:** PHP وVue والمسار تم فحصها؛ تعذر Pest بسبب عدم توفر executable في `vendor`. يلزم نشر الكود وبناء الواجهة.

### 3.15 الحملة 8466 متوقفة بسبب Rate Limit وTimeout

- **مصدر المحادثة:** `019f4bc9-7fe7-78d1-b8a8-54a8080ad6f0`.
- **التصنيف:** Campaign delivery / Meta rate limits.
- **النطاق:** **سيرفر وMeta**.
- **الحالة:** **مشخّص، وليس إصلاحًا نهائيًا للسبب الخارجي**.
- **المشكلة:** الحملة فيها `ongoing` و`pending` وتوقفت. اللوج أظهر تقريبًا 123 طلبًا مرفوضًا: 28 `#130429 Rate limit hit`، و94 `cURL error 28`، وخطأ واحد `#100 Invalid parameter`.
- **السبب الأساسي:** سرعة/توازي الإرسال تجاوز حد WhatsApp/Meta، ثم تراكمت الطلبات وحدثت timeouts. خطأ `Invalid parameter` منفرد وقد يكون مرتبطًا بميديا أو payload معين وليس سبب الفشل الرئيسي.
- **سلوك النظام الحالي:** rate limit يعيد الرسالة إلى `pending` ويؤجل dispatcher نحو 60 ثانية. timeout وinvalid parameter ينتقلان إلى `failed`، ثم retry حسب `5,15,60` دقيقة إذا كان مفعّلًا. `completed` تعني انتهاء المعالجة وليس نجاح كل الرسائل.
- **الحل التشغيلي:** خفض `phone_mps` و`concurrency`، مراقبة عدد workers، والتحقق من إعدادات المنظمة، ثم إعادة تشغيل workers بعد النشر. لا ترفع التوازي قبل مقارنة المعدل الفعلي مع حد Meta.
- **بيانات مفيدة:** يمكن قياس الرسائل الناجحة/الثانية من `campaign_logs`، وعدد workers من Supervisor، والإعدادات من `php artisan campaign:config ORG_ID --show`.

### 3.16 مصدر إعدادات الحملات وعدد workers

- **مصدر المحادثة:** `019f4d95-2700-72d3-8b30-43ad917990ac`.
- **التصنيف:** Campaign operations / configuration.
- **النطاق:** **سيرفر**.
- **الحالة:** **معلومة تشغيلية مع خطوة نشر**.
- **الخلاصة:** السرعة تعتمد على `phone_mps`, `concurrency`, `request_delay_ms`, وعدد عمال `campaign-send`. إعداد Supervisor المشار إليه كان `numprocs=12`.
- **أوامر التحقق:** SQL على `campaign_logs` لقياس النجاح في كل ثانية، `sudo supervisorctl status campaign-send:*`، و`php artisan campaign:config ORG_ID --show`.
- **بعد تعديل الإعدادات:** `php artisan config:clear`, `cache:clear`, `queue:restart`، وإعادة تشغيل مجموعات Supervisor المناسبة.

### 3.17 عدم الوصول إلى Ollama محليًا أو عدم وجود النموذج

- **مصادر المحادثات:** `019f6a72-6676-7e21-8a58-320a62818c7b`, `6a58ad39-bc1c-83eb-b46b-cf0faafa772d`.
- **التصنيف:** AI provider / Local development.
- **النطاق:** **لوكال**.
- **الحالة:** **مكتمل عبر بديل OpenRouter؛ مسار Ollama الأصلي مشخّص**.
- **المشكلة:** `Unable to reach the local Ollama service`، أو `ollama` غير معروف في PowerShell، أو النموذج `gemma3:4b` غير مثبت.
- **حل Ollama:** التأكد من تثبيت `ollama.exe`، تحديث PATH أو إعادة فتح PowerShell، اختبار `http://localhost:11434` و`curl.exe http://localhost:11434/api/tags`، ثم `ollama list` و`ollama pull gemma3:4b`.
- **الحل الذي تم اعتماده:** تحويل مزود AI إلى OpenRouter، ضبط `openrouter/free`، تحديث `AiService.php` ورسالة الخطأ، وإيقاف تنزيل Ollama.
- **تحذير أمني:** مفتاح OpenRouter الذي ظهر في المحادثة يجب إلغاؤه وإصدار مفتاح جديد.

### 3.18 GitHub Models لا يوفر GPT مجانيًا بلا حدود

- **مصدر المحادثة:** `6a607c30-2580-83ed-b867-a0d88f7a4e8d`.
- **التصنيف:** AI provider / Product limitation.
- **النطاق:** **معلومة، لا يوجد إصلاح برمجي**.
- **الحالة:** **مكتمل كمعلومة**.
- **الخلاصة:** GitHub Models كان يوفر تجربة مجانية بحدود، ولا يوجد استخدام رسمي لنماذج OpenAI بلا limits وبدون دفع. البدائل العملية: OpenAI API مدفوع حسب الاستخدام، OpenRouter حسب مزوده، أو نموذج مفتوح المصدر عبر Ollama محليًا/على سيرفر.

### 3.19 MCP Chatbot Flow Automation وGitHub Models API

- **مصدر المحادثة:** `019f8627-7c73-7f51-8ded-3a151dcf23a5`.
- **التصنيف:** MCP / AI automation / security.
- **النطاق:** **مشترك**؛ التنفيذ محلي، والربط النهائي سيرفر ومزود AI.
- **الحالة:** **مكتمل وظيفيًا، إعداد المفتاح والربط يحتاجان إدارة آمنة**.
- **المشكلة:** فشل الوصول إلى GitHub Models بسبب API key/model/account credits، والحاجة إلى أن يفهم MCP معطيات الشات بوت ويستفسر عن الناقص ويبني Flow من العقد الموجودة.
- **الحل المنفذ:**
  - كتالوج فعلي للعقد والـActions والحقول والحدود.
  - أسئلة توضيحية 1–3 عند نقص المتطلبات ومنع اختراع IDs أو branches.
  - تحقق خادمي من العقد والروابط و`sourceHandle` والوصول من Start والموارد التابعة للمنظمة.
  - دعم Templates وMetaFlows وPortal Flows وAgents وGroups وLabels وContact Fields.
  - منع AI Response و`send_email` عبر MCP لأسباب أمنية.
  - إنشاء Drafts غير مفعّلة بعد Preview وموافقة صريحة.
  - دعم GitHub Models وOpenRouter.
  - تحديث MCP إلى `2025-06-18` وإضافة Origin validation.
- **الملفات:** `FlowBuilderCatalog.php`, `AutomationMcpService.php`, `AutomationBuilderAgentService.php`, `AiService.php`, `MCP_AUTOMATION_SETUP.md`, واختبارات MCP.
- **التحقق:** 18 اختبارًا و54 assertion، build ناجح، و`npm run build` بنجاح. اختبارات Unit العامة فيها مشاكل قديمة في Pest/Windows.
- **المطلوب:** استخدام API key جديد محفوظ في `.env`/secret manager، وعدم وضعه في المحادثات أو اللوج.

### 3.20 إنشاء MCP Server وربطه بـ ChatGPT Workspace

- **مصادر المحادثات:** `019f64c2-89c7-7090-862d-d0a59160b06a`, `6a5767cb-527c-83ed-9863-ce794694d6d4`, `6a573282-3b90-83eb-87c0-958ee54d2173`.
- **التصنيف:** MCP / OAuth / Workspace.
- **النطاق:** **سيرفر وخارجي ChatGPT Workspace**.
- **الحالة:** **كود MCP مكتمل؛ الربط الإنتاجي يحتاج إعدادًا**.
- **الحل:** خادم `POST /api/mcp` مع Laravel Passport وOAuth 2.1، scope `mcp:automation`، عزل المؤسسة، Preview وconfirmation token وAudit Logs، وإنشاء Drafts فقط.
- **روابط الإنتاج:** `https://dashboard.chatberry.net/api/mcp` ومسارات OAuth و`.well-known` المقابلة.
- **المطلوب على السيرفر:** `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan passport:keys --force`, `php artisan optimize:clear`، ثم إنشاء Public OAuth client مع Redirect URI الذي يعرضه ChatGPT.
- **المطلوب في Workspace:** Business أو Enterprise/Edu، صلاحية Owner/Admin، إنشاء Custom MCP App، استخدام OAuth 2.1 وPKCE، والحفاظ على قيمة `resource` حتى تبادل التوكن.
- **ملاحظة أمنية:** لا تسمح بـ SQL مباشر من ChatGPT إلى production؛ المسار الآمن هو MCP tools ثم Laravel services ثم قاعدة البيانات.

### 3.21 تجربة QIPS chatbot محليًا

- **مصدر المحادثة:** `019f60b2-4ec2-74b0-a1cc-540dfa7bec04`.
- **التصنيف:** WhatsApp webhook / Flow automation / local testing.
- **النطاق:** **مشترك**؛ الاختبار لوكال، والتجربة الحقيقية تحتاج Meta وسيرفر قابل للوصول.
- **الحالة:** **مكتمل كطريقة اختبار، يحتاج رقم اختبار حقيقي للنتيجة الخارجية**.
- **الحل المحلي:** تشغيل Laravel على 8000، queue على قوائم webhook، و`schedule:work`، ثم إرسال payload إلى `POST /webhook/29` مع `id` و`wamid` مختلفين في كل مرة.
- **للتجربة الحقيقية:** استخدام رقم WhatsApp test recipient، نفق ngrok، وتحديث Callback URL في Meta مؤقتًا.
- **القيود:** Postman قد يسجل الرسالة محليًا، لكنه لا يثبت أن Meta قبلت إرسال الرد الفعلي. يجب اختبار رقم مصرح به لدى Meta.

### 3.22 خطأ Composer وغياب `vendor/autoload.php`

- **مصادر المحادثات:** `6a58a77f-1a58-83eb-afcf-7ce4e1b4abeb`, `6a576ecb-1eac-83eb-a569-ec0f95559fa8`.
- **التصنيف:** Laravel / Composer / Local development.
- **النطاق:** **لوكال** إذا ظهر في Windows، و**سيرفر** إذا ظهر أثناء deploy.
- **الحالة:** **إجراء إصلاح معروف؛ نجاحه يعتمد على اكتمال Composer**.
- **المشكلة:** `vendor/autoload.php` مفقود، أو تثبيت الحزم انقطع، أو `post-autoload-dump` يشير إلى `myclabs/deep-copy` غير موجود.
- **الحل:** عدم حذف `composer.lock`، تنظيف Composer cache، حذف `vendor` التالف فقط، ثم `composer install`. على Windows يمكن استخدام `--no-scripts` أولًا، التحقق من `vendor/myclabs/deep-copy/...`, ثم `composer dump-autoload --optimize` و`php artisan package:discover`.
- **عند كثرة فشل الحزم:** فحص PHP extensions (`curl`, `openssl`, `zip`)، تشغيل PowerShell، تثبيت/فحص 7-Zip، تقليل التوازي، استخدام IPv4، وتجنب تدخل Windows Defender.
- **ملاحظة:** حذف `vendor` قابل لإعادة الإنشاء من `composer.lock`; لا تحذف lock file دون سبب.

### 3.23 Laravel File Cache: `file_put_contents ... No such file or directory`

- **مصادر المحادثات:** `019f5ba6-c05a-7af3-996d-dab607f63229`, `6a632346-b520-83ed-8ffe-6a853ccc8e0f`, `6a5dcf2c-a51c-83eb-882d-819a244eab07`.
- **التصنيف:** Production infrastructure / Laravel cache permissions.
- **النطاق:** **سيرفر**.
- **الحالة:** **حل تشغيلي واضح، مع توصية دائمة باستخدام Redis**.
- **المشكلة:** Laravel لا يستطيع إنشاء مجلدات `storage/framework/cache/data/...` أو يعمل PHP-FPM وworkers بمستخدم لا يملك الكتابة.
- **الحل الفوري:** إنشاء `storage/framework/cache/data`, sessions, views, logs و`bootstrap/cache`، ضبط الملكية لمستخدم PHP-FPM، ثم `chmod` مناسب، وتشغيل Artisan بالمستخدم نفسه، وإعادة تشغيل PHP-FPM والـworkers.
- **الحل الدائم:** استخدام `CACHE_STORE=redis`/`CACHE_DRIVER=redis` في الإنتاج، منع deployment scripts من حذف المجلد، وإضافة فحص writable ضمن deployment.
- **تحذير:** لا تستخدم `chmod -R 777`.

### 3.24 `env()` تظهر `null` داخل Tinker بعد `config:cache`

- **مصدر المحادثة:** `6a632346-b520-83ed-8ffe-6a853ccc8e0f`.
- **التصنيف:** Laravel configuration / deployment.
- **النطاق:** **سيرفر** غالبًا، وقد يظهر لوكال.
- **الحالة:** **مكتمل كتوصية إصلاح**.
- **السبب:** بعد `php artisan config:cache` لا ينبغي الاعتماد على `env()` خارج ملفات `config`. Laravel يستخدم cached config ولا يقرأ `.env` بالطريقة المتوقعة داخل Tinker/Controllers/Jobs.
- **الحل:** إضافة `config/campaign.php` بقيمة `env('CAMPAIGN_CHUNK_SIZE', 500)`، ثم استخدام `config('campaign.chunk_size')` في بقية التطبيق، وبعدها `optimize:clear`, `config:cache`, `queue:restart` وإعادة تشغيل workers.
- **التحقق:** اختبر `config('campaign.chunk_size')` بدل `env(...)`.

### 3.25 دعوات البريد لا تُرسل من الإنتاج

- **مصدر المحادثة:** `019f5b77-e942-7fe1-8140-4fd2273ae0e9`.
- **التصنيف:** Queue / SMTP / Email delivery.
- **النطاق:** **سيرفر**.
- **الحالة:** **السبب محدد، والإصلاح يحتاج توحيد queue ثم فحص SMTP**.
- **المشكلة الأولى:** التطبيق يضع `CustomEmail` في database queue، بينما Supervisor يشغل workers على Redis. لذلك تبقى jobs في جدول `jobs` ولا يلتقطها العامل.
- **الحل:** توحيد `QUEUE_CONNECTION=redis` مع Supervisor، ثم `config:clear`, `queue:restart`، وrestart لمجموعة worker الصحيحة. لمعالجة jobs العالقة يمكن تشغيل database worker، لكن ذلك سيرسل الرسائل فعليًا ويجب تنفيذه بحذر.
- **المشكلة الثانية:** Hostinger أعاد `554 5.7.1 Client host rejected` من IPv6 السيرفر. هذا رفض من مزود SMTP، وليس خطأ في إنشاء الدعوة.
- **الحل الخارجي:** تصحيح `MAIL_USERNAME` إن كان مكتوبًا خطأ، تدوير كلمة مرور البريد المكشوفة، مسح config cache، تجربة 465/SSL أو 587/TLS، ثم طلب whitelist/حل IPv6/PTR من Hostinger أو استخدام مزود transactional مثل SES/Mailgun/Resend.

### 3.26 صلاحيات ملف slow queries log

- **مصدر المحادثة:** `6a5626c9-9890-83ed-9ff8-ab2cc8fc4d85`.
- **التصنيف:** Production filesystem / logging.
- **النطاق:** **سيرفر**.
- **الحالة:** **حل تشغيلي مكتمل**.
- **المشكلة:** PHP-FPM لا يستطيع الكتابة إلى `storage/logs/slow-queries-*.log` لأن الملف أنشأه root أو مستخدم مختلف.
- **الحل:** `chown` لـ`storage bootstrap/cache`، صلاحيات مجلدات 2775 وملفات 664، ضبط ACL افتراضي لـ`www-data`، وعدم تشغيل Artisan بـ`sudo` كمستخدم root.
- **التحقق:** اختبار الكتابة بمستخدم PHP-FPM، ثم `optimize:clear` وإعادة تشغيل PHP-FPM.
- **تحسين اختياري:** استخدام daily log مع retention، وحماية `DB::listen` حتى لا يؤدي فشل logging إلى تعطيل الطلب الأساسي.

### 3.27 دعوة المستخدم لا تصل رغم إنشاء job

- **مصدر المحادثة:** نفس `019f5b77-e942-7fe1-8140-4fd2273ae0e9`.
- **التصنيف:** Email / Queue.
- **النطاق:** **سيرفر**.
- **الحالة:** **مشخّص**.
- **الدليل:** Subject وrecipient وlink صحيحون، والـworker حاول الإرسال، لكن SMTP أعاد رفضًا للـhost/IPv6. لا يوجد دليل أن المشكلة في payload الدعوة.
- **الإجراء:** بعد إصلاح queue وSMTP، إعادة محاولة job المحددة ومراقبة `laravel.log` وemail logs. لا تعِد الإرسال قبل التأكد من عدم وصول الرسالة لتجنب التكرار.

### 3.28 بطء قائمة المحادثات وصفحة الفريق وN+1 queries

- **مصدر المحادثة:** `019f582a-cfd9-7f23-a21e-4ab7c256e2e5`.
- **التصنيف:** Database performance / Laravel queries.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا، migration/deploy مطلوب**.
- **المشاكل:** `latestOfMany` ثقيل، استعلام unread وآخر inbound لكل contact، N+1 في `getChatMessages`، `paginate()` يسبب count، وتكرار استعلام المنظمة لتحويل الوقت.
- **الحل:** batch hydration، eager/grouped loading، `simplePaginate()`, cache لـDateTimeHelper، تقليل الأعمدة في `/team`، وعدم إضافة indexes مكررة دون EXPLAIN.
- **التحقق:** `getChatMessages` أصبح عدد استعلاماته ثابتًا تقريبًا، ونجحت اختبارات optimization الموجهة. فشل بعض الاختبارات العامة بسبب MySQL test credentials أو توقعات قديمة.
- **الملفات:** `ChatService.php`, `Contact.php`, `ContactResource.php`, `TeamController.php`, واختبارات optimization.

### 3.29 بطء `/notifications/unread-count` و`mark-unread`

- **مصدر المحادثة:** `019f5685-a3c7-7353-a3e2-e70a5d41661b` و`6a539787-83f8-83eb-b5f6-98f731ffef1e`.
- **التصنيف:** Database performance / Notifications / UX.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا، migration مطلوب في الإنتاج**.
- **الحل:**
  - cache لمدة 15 ثانية لعداد الإشعارات مع invalidation عند الإنشاء/القراءة.
  - فهرس `idx_notification_logs_unread_count`، وأظهر EXPLAIN أنه يقرأ عددًا قليلًا من الصفوف.
  - فهرس covering لـsubscriptions وفهرس latest inbound chat.
  - تقليل select إلى `id` وإزالة شروط SoftDeletes المكررة.
  - إضافة `POST /chats/{uuid}/mark-unread` وتحديث الواجهة دون reload.
- **التحقق:** تحسن القياس المحلي من نحو 4271ms/50 عملية مباشرة إلى نحو 55ms عبر cache، ونجح build. بعض Feature tests تعذر تشغيلها بسبب إعداد MySQL المحلي.
- **المطلوب:** تشغيل migrations على الإنتاج، ثم مراقبة cache hit rate وEXPLAIN.

### 3.30 إضافة Resource usage للمنظمات

- **مصدر المحادثة:** `019f525f-6048-75a1-9acb-08629789d992`.
- **التصنيف:** Admin CRM / Billing performance.
- **النطاق:** **مشترك**.
- **الحالة:** **مكتمل برمجيًا**.
- **المشكلة:** الحاجة إلى إرجاع usage مع كل منظمة، مع خطر N+1 عند حساب summary لكل منظمة داخل loop.
- **الحل:** `CRMOrganizations` يعيد `resource_usage`، واستخدام `bulkSummaries()` بدل `summary()` لكل منظمة، وإضافة فهارس مركبة على subscriptions وresource addons وusage events وcontacts وteams وteam invites.
- **الملفات:** `OrganizationService.php`, `SubscriptionUsageService.php`, migration performance، واختبارات الخدمة.
- **التحقق:** PHP syntax نجح؛ اختبار MySQL تعذر بسبب `Access denied`.

### 3.31 مشاركة موارد الخطة بين المنظمات

- **مصدر المحادثة:** `019f5153-0c86-7360-b3e7-0344ad84e1d4`.
- **التصنيف:** Billing / Subscription limits / Resource sharing.
- **النطاق:** **مشترك**.
- **الحالة:** **جزئي/يحتاج إصلاحًا قبل الإنتاج**.
- **المشاكل المكتشفة:**
  - عند تعطيل sharing، `organizations_limit` يعتمد على `resourcePoolOrganizationCount()` وقد يعيد 1 بدل عد المنظمات المملوكة للمستخدم؛ يمكن تجاوز الحد.
  - cache `resource_sharing_enabled` صالح 10 دقائق ولا يتم مسحه عند تحديث plan أو subscription.
  - لا توجد تغطية كافية لسيناريو parent organization + linked organizations.
- **الحل المقترح:** استخدام `ownedOrganizationsCount()` في الوضع غير المشترك، و`Cache::forget()` عند تحديث الخطة/الاشتراك، وإضافة اختبارات end-to-end للاستهلاك المشترك.
- **قرار الاستخدام:** إذا كانت الحسابات Organizations منفصلة، الحل الحالي الآمن هو ربطها عبر billing/resource pool المدعوم. إذا لم يكن ذلك مفعّلًا، جعلها Users داخل Organization واحدة.
- **المطلوب:** تنفيذ الإصلاحين، تشغيل اختبارات قاعدة البيانات، ثم migration/deploy. لا تعتمد على sharing إنتاجيًا قبل ذلك.

### 3.32 Backfill يفشل بسبب Foreign Key على contacts

- **مصدر المحادثة:** `019ef431-904f-72d2-b889-717df55daff8`.
- **التصنيف:** Data migration / Subscription limits / Large data.
- **النطاق:** **مشترك وسيرفر**.
- **الحالة:** **مكتمل كإصلاح كود، التنفيذ الإنتاجي مطلوب**.
- **المشكلة:** `conversation_windows_contact_id_foreign` يفشل لأن سجلات `chats` أو `chats_archive` تشير إلى contacts محذوفة/غير موجودة.
- **الحل:** `join` مع `contacts` والتحقق من `contacts.id` و`organization_id` في جدولي chats وarchive، وتجاهل البيانات اليتيمة بدل كسر المهمة.
- **تحسين 11 مليون صف:** معالجة archive ثم chats، `cursor()` streaming، ترتيب متوافق مع index، batch inserts، `--insert-batch`, `--progress-every`، وتقليل queries على `conversation_windows`.
- **أوامر الإنتاج:** تشغيل migration، ثم `--dry-run --progress-every=100000 --report-file=...`، وبعد المراجعة التشغيل الحقيقي بـ`--insert-batch=1000`. يفضل وقت ضغط منخفض وإيقاف archive jobs أثناء backfill.

### 3.33 إنشاء flows بالإنجليزية ونقلها للسيرفر

- **مصدر المحادثة:** `019f7a1a-7fe1-7c12-b37e-dc33d6101fd9`.
- **التصنيف:** Data operation / FlowBuilder / deployment.
- **النطاق:** **مشترك، والبيانات النهائية على السيرفر**.
- **الحالة:** **النسخ المحلية أُنشئت؛ النقل يحتاج فحص IDs ثم import**.
- **النتيجة:** إنشاء 100 flow إنكليزي و95 trigger، تحديث Portal links، والتحقق من عدم وجود روابط مكسورة أو نص عربي ظاهر.
- **الخطر:** روابط Portal تعتمد IDs؛ يجب الحفاظ على IDs أو إعادة ربطها.
- **الحل:** تصدير SQL بـ`mysqldump`، فحص تعارض IDs على السيرفر، ثم import بالترتيب. لا تستورد مباشرة إذا كانت IDs موجودة.
- **المطلوب:** backup، فحص التعارض، import في نافذة صيانة أو بخطة rollback، ثم تحقق من counts والروابط.

### 3.34 MySQL Binary Logs و`REPLICATION CLIENT`

- **مصدر المحادثة:** `6a5ddd3c-21d8-83ed-be53-d4c981685745`.
- **التصنيف:** Database administration / audit.
- **النطاق:** **سيرفر**.
- **الحالة:** **معلومة تشغيلية**.
- **المشكلة:** `SHOW BINARY LOGS` أعاد `#1227 Access denied`.
- **الحل الآمن:** تنفيذ التحقيق بحساب root أو حساب إداري منفصل، أو منح مستخدم محدد `GRANT REPLICATION CLIENT ON *.*` بحسب host الحقيقي (`localhost` أو `%`). لا تمنح `SUPER` لمستخدم التطبيق.
- **التحقق:** `SELECT USER(), CURRENT_USER()`, `SHOW GRANTS`, `SHOW VARIABLES LIKE 'log_bin'`, ثم `SHOW BINARY LOGS`.
- **تحذير:** أي كلمة مرور DB ظهرت في المحادثة يجب تدويرها فورًا وتحديث `.env` ثم config cache.

### 3.35 شرح `EXPLAIN` و`ANALYZE TABLE`

- **مصدر المحادثة:** `6a539787-83f8-83eb-b5f6-98f731ffef1e`.
- **التصنيف:** Database diagnostics.
- **النطاق:** **سيرفر/معرفة تشغيلية**.
- **الحالة:** **معلومة**.
- **الخلاصة:** `EXPLAIN SELECT COUNT(...)` يعرض خطة التنفيذ ولا ينفذ العد نفسه؛ يجب مراقبة `type`, `key`, `possible_keys`, `rows`, `Extra`. أما `ANALYZE TABLE notification_logs` فيحدث إحصائيات MySQL للـoptimizer ولا يعدل بيانات الصفوف. زمن 188ms لجدول كبير ليس بالضرورة مشكلة.

### 3.36 Composer وLaravel على السيرفر/اللوكال: فصل نوع العطل

- **الملاحظة الجامعة:** أخطاء `vendor/autoload.php`, `myclabs/deep-copy`, أو فشل package downloads هي أخطاء بيئة Composer، وليست أخطاء تطبيق Laravel. يجب إصلاح اكتمال dependency install قبل تفسير أخطاء التطبيق أو تشغيل الاختبارات.
- **إجراء التحقق:** `composer validate`, `composer diagnose`, وجود `vendor/autoload.php`, ثم `composer dump-autoload -o`.
- **أثر متكرر في التقرير:** كثير من اختبارات Pest المحلية فشلت بسبب MySQL credentials أو vendor ناقص، لذلك لا يصح اعتبارها فشلًا في التعديل نفسه دون إعادة الاختبار في بيئة مكتملة.

### 3.37 حذف الرسائل عبر WhatsApp API

- **مصدر المحادثة:** `6a5628a4-70c0-83eb-a719-521ca7cff48e`.
- **التصنيف:** WhatsApp API capability.
- **النطاق:** **معلومة**.
- **الخلاصة:** WhatsApp Cloud API لا يوفر endpoint لحذف رسالة أُرسلت إلى محادثة العميل أو تنفيذ Delete for everyone. Webhook `revoke` يخبر النظام بأن المستخدم حذف رسالة من التطبيق، ولا ينفذ الحذف من طرف المنصة. يمكن حذف media من خوادم Meta، لكن ذلك لا يحذف الرسالة من محادثة العميل.
- **الأثر على Chatberry:** إن وُجد زر حذف، يجب تسميته `Delete from Chatberry` أو `Remove from conversation history`، لا `Delete for everyone`.

## 4. المشاكل المتكررة التي لم تكن عطلًا في الكود

1. **Chrome extension errors:** رسائل `chrome-extension://` تخص إضافة المتصفح.
2. **اختبارات Pest:** عدة اختبارات لم تصل إلى assertions بسبب MySQL test user/password أو ربط `Tests\\TestCase` في إعداد Windows القديم.
3. **`ANALYZE TABLE`:** زمن أقل من ثانية لا يعني وجود خلل، خصوصًا بعد فهرسة جدول كبير.
4. **Postman مع WhatsApp:** تسجيل webhook محليًا لا يثبت قبول Meta للرد الحقيقي.
5. **`completed` للحملة:** تعني انتهاء المعالجة، وليس نجاح كل رسالة.
6. **`env()` بعد config cache:** السلوك متوقع؛ استخدم `config()`.
7. **حذف رسالة WhatsApp:** غير مدعوم من Cloud API، وليس endpoint مفقودًا في Chatberry.

## 5. قائمة الإجراءات المطلوبة على السيرفر أو لدى مزود خارجي

### أولوية عاجلة

1. تدوير كل مفاتيح API وكلمات مرور SMTP/DB التي ظهرت في المحادثات أو اللوجات، ثم تحديث secrets و`config:cache`.
2. إصلاح `QUEUE_CONNECTION` ليتطابق مع Supervisor (Redis أو database، وليس الاثنين بشكل متناقض).
3. ضبط ملكية وصلاحيات `storage` و`bootstrap/cache` لمستخدم PHP-FPM، ومنع تشغيل Artisan كـroot.
4. معالجة `#200` عبر Meta: System User, WABA assets, scopes, granular targets، ثم إعادة إصدار التوكن.
5. خفض campaign `phone_mps/concurrency` للحملة المتأثرة ومراقبة `#130429` وtimeouts.

### أولوية عالية

6. نشر RBAC والمigrations ومسح Laravel cache وإعادة تشغيل PHP-FPM/queue workers.
7. نشر إصلاح PDF filename وإصلاح Flow `current_step` ثم إعادة تشغيل workers.
8. تشغيل migration الخاصة بـ unread count وmark-unread وresource usage.
9. تنفيذ backfill الخاص بـ `conversation_windows` أولًا بـ dry-run ثم تشغيله بدفعات.
10. إصلاح إعدادات SMTP أو نقل البريد إلى مزود transactional إذا استمر رفض Hostinger للـIPv6.

### أولوية متوسطة

11. تطبيق hardening لأزرار القوالب في الحملات، وإصلاح metadata للحملات المجدولة التي تحتوي `buttons: []`.
12. إكمال إصلاح resource sharing: عدّ المنظمات غير المشتركة، invalidation للكاش، واختبارات السيناريو الرئيسي/التوابع.
13. تحديث QIPS/n8n لمنع إعادة إرسال `message.sent` كـinbound webhook.
14. اختبار رفع Carousel حيًا بحد concurrency منخفض وتسجيل استجابة Meta دون تسريب التوكن.
15. إكمال ربط MCP في Workspace باستخدام OAuth 2.1 وPKCE وscope محدود.

## 6. الخلاصة النهائية حسب مكان الحل

| مكان التنفيذ | أمثلة المشاكل | ما يلزم |
|---|---|---|
| **لوكال فقط** | PATH/Ollama، Composer/vendor، Vite `public/hot`، بعض إعدادات الاختبار | إصلاح البيئة وإعادة البناء/الاختبار. |
| **كود محلي ثم سيرفر** | RBAC، PDF filename، API contact name، الحملات المجدولة، unread، resource usage، performance indexes | commit/deploy، migrations، build، cache/queue restart. |
| **سيرفر فقط** | صلاحيات storage/logs، queue mismatch، `.env`/config cache، SMTP، MySQL privileges | أوامر تشغيل وصلاحيات وservice restart. |
| **Meta/مزود خارجي** | `#200`, Coexistence, `#130429`, SMTP Hostinger، QIPS webhook | إعدادات WABA/System User أو مزود الخدمة أو التكامل الخارجي. |
| **قيد رسمي** | حذف رسالة WhatsApp، GPT مجاني بلا limits | لا يوجد إصلاح برمجي؛ يجب تصميم UX/بديل مناسب. |

**الاستنتاج:** معظم التعديلات البرمجية المطلوبة تم تنفيذها أو تشخيصها، لكن عددًا من المشاكل لا ينتهي بمجرد تعديل الكود؛ يحتاج نشر migrations، توحيد queue/cache، ضبط صلاحيات النظام، أو تعديل إعدادات Meta وHostinger وQIPS. لذلك يجب اعتبار عناصر القسم الخامس checklist إلزامية قبل إغلاق المشاكل إنتاجيًا.
