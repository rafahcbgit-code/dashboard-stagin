<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$organizationId = (int) ($argv[1] ?? 285);
$bundleId = 'rento-car-rental-v1';

$organization = DB::table('organizations')->where('id', $organizationId)->first();
if (!$organization) {
    throw new RuntimeException("Organization {$organizationId} was not found.");
}
if (DB::table('flows')->where('organization_id', $organizationId)->where('metadata', 'like', '%"import_bundle_id":"' . $bundleId . '"%')->exists()) {
    throw new RuntimeException("Bundle {$bundleId} is already installed for organization {$organizationId}.");
}

$maxButtonLength = 20;
$flows = [];
$flowBuilders = [];
$triggers = [];

$addFlow = static function (string $key, string $name) use (&$flows): void {
    if (isset($flows[$key])) {
        throw new RuntimeException("Flow key {$key} already exists.");
    }
    $flows[$key] = ['uuid' => (string) Str::uuid(), 'name' => $name, 'id' => null];
};

$labelLength = static function (string $label): int {
    return function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label);
};

$addTrigger = static function (string $key, string $label, string $target) use (&$triggers, &$flows, $labelLength, $maxButtonLength): array {
    if (!isset($flows[$target])) {
        throw new RuntimeException("Trigger {$key} points to unknown flow {$target}.");
    }
    if ($labelLength($label) > $maxButtonLength) {
        throw new RuntimeException("Button/trigger {$label} exceeds {$maxButtonLength} characters.");
    }
    if (isset($triggers[$key])) {
        throw new RuntimeException("Trigger key {$key} already exists.");
    }
    $triggers[$key] = ['uuid' => (string) Str::uuid(), 'name' => $label, 'target' => $target];
    return ['key' => $key, 'label' => $label, 'target' => $target];
};

$node = static function (string $flowUuid, string $id, string $type, string $label, array $fields = []): array {
    return [
        'id' => $id,
        'type' => $type,
        'label' => $label,
        'position' => ['x' => 0, 'y' => 0],
        'data' => ['uuid' => $flowUuid, 'title' => $label, 'metadata' => ['fields' => $fields]],
    ];
};

$edge = static function (string $id, string $source, string $target, ?string $handle = null): array {
    $result = ['id' => $id, 'source' => $source, 'target' => $target];
    if ($handle !== null) {
        $result['sourceHandle'] = $handle;
    }
    return $result;
};

$buttonFields = static function (string $body, array $buttons) use ($labelLength, $maxButtonLength): array {
    foreach ($buttons as $button) {
        if ($button !== '' && $labelLength($button) > $maxButtonLength) {
            throw new RuntimeException("Button {$button} exceeds {$maxButtonLength} characters.");
        }
    }
    return [
        'type' => 'interactive buttons',
        'headerType' => 'none',
        'headerText' => '',
        'headerMedia' => [],
        'body' => $body !== '' ? $body : (string) (array_values(array_filter($buttons))[0] ?? ''),
        'footer' => '',
        'buttonType' => 'buttons',
        'buttons' => array_replace(['button1' => '', 'button2' => '', 'button3' => ''], $buttons),
        'ctaUrlButton' => ['displayText' => '', 'url' => ''],
    ];
};

$listFields = static function (string $body, array $items, string $buttonLabel, string $sectionTitle) use ($labelLength, $maxButtonLength): array {
    if ($labelLength($buttonLabel) > $maxButtonLength) {
        throw new RuntimeException("List button {$buttonLabel} exceeds {$maxButtonLength} characters.");
    }
    foreach ($items as $item) {
        if ($labelLength($item['title']) > $maxButtonLength) {
            throw new RuntimeException("List row {$item['title']} exceeds {$maxButtonLength} characters.");
        }
    }
    return [
        'type' => 'interactive list',
        'headerType' => 'none',
        'headerText' => '',
        'headerMedia' => [],
        'body' => $body,
        'footer' => '',
        'buttonLabel' => $buttonLabel,
        'sections' => [['title' => $sectionTitle, 'rows' => $items]],
        'selection_variable' => 'selected_option',
        'selection_mode' => 'replace',
    ];
};

$portalFields = static function (string $label, string $target, string $triggerKey) use (&$flows, &$triggers): array {
    return [
        'type' => 'portal',
        'target_flow_uuid' => $flows[$target]['uuid'],
        'target_flow_name' => $flows[$target]['name'],
        'target_flow_id' => 0,
        'connection_mode' => 'with_trigger',
        'trigger_name' => $label,
        'trigger_keyword' => $label,
        'trigger_created' => true,
        'trigger_uuid' => $triggers[$triggerKey]['uuid'],
    ];
};

$register = static function (string $id, string $label, string $target) use ($addTrigger): array {
    return $addTrigger($id, $label, $target);
};

// Flow inventory: every language, menu option, form submission, and response has its own flow.
$addFlow('start', 'Rento | البداية واختيار اللغة');
$addFlow('ar_menu', 'Rento | القائمة الرئيسية العربية');
$addFlow('en_menu', 'Rento | English Main Menu');

$menuKeys = [
    'ar_bookings' => 'Rento | الحجوزات', 'en_bookings' => 'Rento | Bookings',
    'ar_contract' => 'Rento | العقد الساري', 'en_contract' => 'Rento | Active Contract',
    'ar_roadside' => 'Rento | المساعدة على الطريق', 'en_roadside' => 'Rento | Roadside Assistance',
    'ar_complaints' => 'Rento | الشكاوى', 'en_complaints' => 'Rento | Complaints',
    'ar_faq' => 'Rento | الأسئلة الشائعة', 'en_faq' => 'Rento | FAQ',
];
foreach ($menuKeys as $key => $name) {
    $addFlow($key, $name);
}

$formDefinitions = [
    'ar_new_booking' => ['Rento | حجز جديد', 'ar', 'حجز جديد', [['الاسم الكامل', 'الاسم الكامل', 'full_name', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل إضافية / ملاحظة', 'تفاصيل إضافية / ملاحظة', 'notes', 'text']], 'ar_new_booking_confirm'],
    'ar_modify_booking' => ['Rento | تعديل حجز', 'ar', 'تعديل حجز', [['رقم الحجز', 'رقم الحجز', 'booking_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل إضافية / ملاحظة', 'تفاصيل إضافية / ملاحظة', 'notes', 'text']], 'ar_modify_booking_confirm'],
    'ar_cancel_booking' => ['Rento | إلغاء حجز', 'ar', 'إلغاء حجز', [['رقم الحجز', 'رقم الحجز', 'booking_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل إضافية / ملاحظة', 'تفاصيل إضافية / ملاحظة', 'notes', 'text']], 'ar_cancel_booking_confirm'],
    'en_new_booking' => ['Rento | New Booking', 'en', 'New Booking', [['Customer Name', 'Customer Name', 'full_name', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Additional Details / Note', 'Additional Details / Note', 'notes', 'text']], 'en_new_booking_confirm'],
    'en_modify_booking' => ['Rento | Modify Booking', 'en', 'Modify Booking', [['Booking Number', 'Booking Number', 'booking_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Additional Details / Note', 'Additional Details / Note', 'notes', 'text']], 'en_modify_booking_confirm'],
    'en_cancel_booking' => ['Rento | Cancel Booking', 'en', 'Cancel Booking', [['Booking Number', 'Booking Number', 'booking_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Additional Details / Note', 'Additional Details / Note', 'notes', 'text']], 'en_cancel_booking_confirm'],
    'ar_extend_contract' => ['Rento | تمديد العقد', 'ar', 'تمديد العقد', [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل إضافية / ملاحظة', 'تفاصيل إضافية / ملاحظة', 'notes', 'text']], 'ar_extend_contract_confirm'],
    'ar_check_dues' => ['Rento | الاستعلام عن مديونية', 'ar', 'الاستعلام عن مديونية', [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل إضافية / ملاحظة', 'تفاصيل إضافية / ملاحظة', 'notes', 'text']], 'ar_check_dues_confirm'],
    'en_extend_contract' => ['Rento | Extend Contract', 'en', 'Extend Contract', [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Additional Details / Note', 'Additional Details / Note', 'notes', 'text']], 'en_extend_contract_confirm'],
    'en_check_dues' => ['Rento | Check Dues', 'en', 'Inquire Balance / Dues', [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Additional Details / Note', 'Additional Details / Note', 'notes', 'text']], 'en_check_dues_confirm'],
    'ar_emergency_repair' => ['Rento | صيانة طارئة', 'ar', 'صيانة طارئة على الطريق', [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل الحالة الطارئة', 'يرجى ذكر الموقع ووصف الحالة الطارئة...', 'emergency_details', 'text']], 'ar_emergency_repair_confirm'],
    'ar_accident' => ['Rento | بلاغ حادث', 'ar', 'بلاغ حادث مروري', [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل الحالة الطارئة', 'يرجى ذكر الموقع ووصف الحالة الطارئة...', 'emergency_details', 'text']], 'ar_accident_confirm'],
    'ar_emergency_support' => ['Rento | طوارئ العملاء', 'ar', 'خدمة عملاء طوارئ', [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل الحالة الطارئة', 'يرجى ذكر الموقع ووصف الحالة الطارئة...', 'emergency_details', 'text']], 'ar_emergency_support_confirm'],
    'en_emergency_repair' => ['Rento | Emergency Repair', 'en', 'Emergency Road Maintenance', [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Emergency Details', 'Please specify location and emergency details...', 'emergency_details', 'text']], 'en_emergency_repair_confirm'],
    'en_accident' => ['Rento | Accident Report', 'en', 'Traffic Accident Report', [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Emergency Details', 'Please specify location and emergency details...', 'emergency_details', 'text']], 'en_accident_confirm'],
    'en_emergency_support' => ['Rento | Emergency Support', 'en', 'Emergency Customer Service', [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Emergency Details', 'Please specify location and emergency details...', 'emergency_details', 'text']], 'en_emergency_support_confirm'],
];

$complaintTypes = [
    ['ar_branch_complaint', 'Rento | شكوى فرع', 'ar', 'شكوى فرع', 'شكوى فرع'],
    ['ar_staff_complaint', 'Rento | شكوى موظف', 'ar', 'شكوى موظف', 'شكوى موظف'],
    ['ar_maintenance_complaint', 'Rento | شكوى صيانة', 'ar', 'شكوى صيانة', 'شكوى صيانة'],
    ['ar_cleanliness_complaint', 'Rento | شكوى نظافة', 'ar', 'شكوى نظافة', 'شكوى نظافة'],
    ['ar_other_complaint', 'Rento | شكوى أخرى', 'ar', 'شكوى أخرى', 'شكوى أخرى'],
    ['en_branch_complaint', 'Rento | Branch Complaint', 'en', 'Branch Complaint', 'Branch Complaint'],
    ['en_staff_complaint', 'Rento | Staff Complaint', 'en', 'Staff Complaint', 'Staff Complaint'],
    ['en_maintenance_complaint', 'Rento | Maintenance Complaint', 'en', 'Maintenance Complaint', 'Maintenance Complaint'],
    ['en_cleanliness_complaint', 'Rento | Cleanliness Complaint', 'en', 'Cleanliness Complaint', 'Cleanliness Complaint'],
    ['en_other_complaint', 'Rento | Other Complaint', 'en', 'Other Complaint', 'Other Complaint'],
];
foreach ($complaintTypes as [$key, $name, $language, $label, $title]) {
    $confirmKey = $key . '_confirm';
    $addFlow($key, $name);
    $formDefinitions[$key] = [$name, $language, $title, $language === 'ar'
        ? [['رقم العقد', 'رقم العقد', 'contract_number', 'text'], ['رقم الجوال', 'رقم الجوال', 'mobile', 'phone'], ['تفاصيل الشكوى', 'اكتب تفاصيل الشكوى بدقة...', 'complaint_details', 'text']]
        : [['Contract Number', 'Contract Number', 'contract_number', 'text'], ['Mobile Number', 'Mobile Number', 'mobile', 'phone'], ['Complaint Details', 'Write complaint details clearly...', 'complaint_details', 'text']], $confirmKey];
}
foreach ($formDefinitions as $formKey => [$name, $language, $title, $fields, $confirmKey]) {
    // Complaint flows are registered above; the remaining request flows are added here.
    if (!isset($flows[$formKey])) {
        $addFlow($formKey, $name);
    }
    if (!isset($flows[$confirmKey])) {
        $addFlow($confirmKey, $name . ' | Confirmation');
    }
}

// FAQ answer flows.
foreach (['ar' => ['ar_branches', 'ar_contact', 'ar_general'], 'en' => ['en_branches', 'en_contact', 'en_general']] as $language => $keys) {
    $addFlow($keys[0], $language === 'ar' ? 'Rento | الفروع والمواقع' : 'Rento | Branches and Maps');
    $addFlow($keys[1], $language === 'ar' ? 'Rento | خدمة العملاء' : 'Rento | Customer Service');
    $addFlow($keys[2], $language === 'ar' ? 'Rento | الأسئلة العامة' : 'Rento | General FAQs');
}

$register('000', '000', 'start');

$allButtons = [];
$menu = static function (string $flowKey, string $body, array $items, string $listLabel, string $sectionTitle) use (&$flowBuilders, &$allButtons, &$flows, &$triggers, $register, $node, $edge, $buttonFields, $listFields, $portalFields): void {
    $flowUuid = $flows[$flowKey]['uuid'];
    $nodes = [$node($flowUuid, 'start', 'start', 'البداية')];
    $useButtons = count($items) <= 3;
    $fields = $useButtons
        ? $buttonFields($body, array_combine(array_map(fn ($i) => 'button' . ($i + 1), range(0, count($items) - 1)), array_column($items, 'label')))
        : $listFields($body, array_map(fn ($item) => ['id' => $item['id'], 'title' => $item['label'], 'description' => $item['description'] ?? ''], $items), $listLabel, $sectionTitle);
    $nodes[] = $node($flowUuid, 'choices', $useButtons ? 'buttons' : 'list', 'الخيارات', $fields);
    $edges = [$edge($flowKey . '-start', 'start', 'choices')];
    foreach ($items as $index => $item) {
        $nodes[] = $node($flowUuid, 'to_' . $item['id'], 'portal', $item['label'], $portalFields($item['label'], $item['target'], $item['key']));
        $edges[] = $edge($flowKey . '-' . $item['id'], 'choices', 'to_' . $item['id'], $useButtons ? chr(97 + $index) : 'a0' . $index);
        $allButtons[] = $item['label'];
    }
    $flowBuilders[$flowKey] = ['nodes' => $nodes, 'edges' => $edges];
};

$button = static function (string $key, string $label, string $target) use ($register): array {
    $item = $register($key, $label, $target);
    $item['id'] = $key;
    return $item;
};

$flowBuilders['start'] = [
    'nodes' => [
        $node($flows['start']['uuid'], 'start', 'start', 'البداية', ['type' => 'keywords', 'keywords' => '000']),
        $node($flows['start']['uuid'], 'language', 'buttons', 'اختيار اللغة', $buttonFields("أهلًا وسهلًا بكم في رينتو لتأجير السيارات 👋\nيسعدنا خدمتكم. يرجى اختيار اللغة:", ['button1' => '🇸🇦 العربية', 'button2' => '🇬🇧 English'])),
    ],
    'edges' => [],
];
$startAr = $button('start_ar', '🇸🇦 العربية', 'ar_menu');
$startEn = $button('start_en', '🇬🇧 English', 'en_menu');
$flowBuilders['start']['nodes'][] = $node($flows['start']['uuid'], 'to_ar', 'portal', $startAr['label'], $portalFields($startAr['label'], 'ar_menu', $startAr['key']));
$flowBuilders['start']['nodes'][] = $node($flows['start']['uuid'], 'to_en', 'portal', $startEn['label'], $portalFields($startEn['label'], 'en_menu', $startEn['key']));
$flowBuilders['start']['edges'] = [$edge('start-language', 'start', 'language'), $edge('start-ar', 'language', 'to_ar', 'a'), $edge('start-en', 'language', 'to_en', 'b')];

$mainAr = [
    $button('ar_main_bookings', '📁 1. الحجوزات', 'ar_bookings'),
    $button('ar_main_contract', '📋 2. عقد ساري', 'ar_contract'),
    $button('ar_main_roadside', '📋 3. المساعدة', 'ar_roadside'),
    $button('ar_main_complaints', '⚠️ 4. الشكاوى', 'ar_complaints'),
    $button('ar_main_faq', '❓ 5. الأسئلة', 'ar_faq'),
];
$mainEn = [
    $button('en_main_bookings', '📁 1. Bookings', 'en_bookings'),
    $button('en_main_contract', '📋 2. Contract', 'en_contract'),
    $button('en_main_roadside', '📋 3. Roadside', 'en_roadside'),
    $button('en_main_complaints', '⚠️ 4. Complaints', 'en_complaints'),
    $button('en_main_faq', '❓ 5. FAQ', 'en_faq'),
];
$menu('ar_menu', "أهلًا بكم في رينتو 👋\nكيف يمكننا خدمتكم اليوم؟ يرجى اختيار الخدمة المطلوبة:", $mainAr, 'اختر الخدمة', 'الخدمات');
$menu('en_menu', "Welcome to Rento 👋\nHow can we help you today? Please select the required service:", $mainEn, 'Choose Service', 'Services');

$arBack = fn (string $key) => $button($key . '_back', '⬅️ الرئيسية', 'ar_menu');
$enBack = fn (string $key) => $button($key . '_back', '⬅️ Main Menu', 'en_menu');

$menu('ar_bookings', 'يرجى اختيار إجراء الحجز المطلوب:', [
    $button('ar_book_new', 'حجز جديد', 'ar_new_booking'), $button('ar_book_modify', 'تعديل حجز', 'ar_modify_booking'), $button('ar_book_cancel', 'إلغاء حجز', 'ar_cancel_booking'), $arBack('ar_bookings'),
], 'اختر الإجراء', 'الحجوزات');
$menu('en_bookings', 'Please select the required booking action:', [
    $button('en_book_new', 'New Booking', 'en_new_booking'), $button('en_book_modify', 'Modify Booking', 'en_modify_booking'), $button('en_book_cancel', 'Cancel Booking', 'en_cancel_booking'), $enBack('en_bookings'),
], 'Choose Action', 'Bookings');

$menu('ar_contract', "خدمات العقود السارية والمساعدة:\nيرجى اختيار الخدمة المطلوبة:", [
    $button('ar_extend', 'تمديد العقد', 'ar_extend_contract'), $button('ar_dues', 'استعلام المديونية', 'ar_check_dues'), $arBack('ar_contract'),
], 'اختر الخدمة', 'العقود');
$menu('en_contract', "Active contract services and assistance:\nPlease select the required service:", [
    $button('en_extend', 'Extend Contract', 'en_extend_contract'), $button('en_dues', 'Check Dues', 'en_check_dues'), $enBack('en_contract'),
], 'Choose Service', 'Contracts');

$menu('ar_roadside', 'اختر القسم التابع للمساعدة على الطريق:', [
    $button('ar_repair', '🚨 صيانة طارئة', 'ar_emergency_repair'), $button('ar_accident', '🚗 بلاغ حادث', 'ar_accident'), $button('ar_emergency', '📞 طوارئ العملاء', 'ar_emergency_support'), $arBack('ar_roadside'),
], 'اختر القسم', 'المساعدة');
$menu('en_roadside', 'Choose the roadside assistance department:', [
    $button('en_repair', '🚨 Emergency Repair', 'en_emergency_repair'), $button('en_accident', '🚗 Accident Report', 'en_accident'), $button('en_emergency', '📞 Emergency Support', 'en_emergency_support'), $enBack('en_roadside'),
], 'Choose Department', 'Roadside');

$arComplaintButtons = [
    $button('ar_complaint_branch', 'شكوى فرع', 'ar_branch_complaint'), $button('ar_complaint_staff', 'شكوى موظف', 'ar_staff_complaint'), $button('ar_complaint_maintenance', 'شكوى صيانة', 'ar_maintenance_complaint'), $button('ar_complaint_cleanliness', 'شكوى نظافة', 'ar_cleanliness_complaint'), $button('ar_complaint_other', 'شكوى أخرى', 'ar_other_complaint'), $arBack('ar_complaints'),
];
$enComplaintButtons = [
    $button('en_complaint_branch', 'Branch Complaint', 'en_branch_complaint'), $button('en_complaint_staff', 'Staff Complaint', 'en_staff_complaint'), $button('en_complaint_maintenance', 'Maintenance Issue', 'en_maintenance_complaint'), $button('en_complaint_cleanliness', 'Cleanliness Issue', 'en_cleanliness_complaint'), $button('en_complaint_other', 'Other Complaint', 'en_other_complaint'), $enBack('en_complaints'),
];
$menu('ar_complaints', 'اختر القسم التابع للشكاوى:', $arComplaintButtons, 'اختر القسم', 'الشكاوى');
$menu('en_complaints', 'Choose the complaints department:', $enComplaintButtons, 'Choose Department', 'Complaints');

$menu('ar_faq', "الأسئلة الشائعة والاستفسارات:\nيرجى اختيار الاستفسار المطلوب:", [
    $button('ar_faq_branches', '📍 الفروع والمواقع', 'ar_branches'), $button('ar_faq_contact', '📞 خدمة العملاء', 'ar_contact'), $button('ar_faq_general', '💡 أسئلة عامة', 'ar_general'), $arBack('ar_faq'),
], 'اختر الاستفسار', 'الاستفسارات');
$menu('en_faq', "FAQs and Inquiries:\nPlease select your inquiry:", [
    $button('en_faq_branches', '📍 Branches & Maps', 'en_branches'), $button('en_faq_contact', '📞 Customer Service', 'en_contact'), $button('en_faq_general', '💡 General FAQs', 'en_general'), $enBack('en_faq'),
], 'Choose Inquiry', 'Inquiries');

$question = static function (string $flowUuid, string $id, string $label, string $questionText, string $variable, string $inputType) use ($node): array {
    return $node($flowUuid, $id, 'question', $label, [
        'type' => 'question', 'question_text' => $questionText, 'variable_name' => $variable,
        'input_type' => $inputType, 'max_retries' => 2,
        'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
    ]);
};

foreach ($formDefinitions as $key => [$name, $language, $title, $fields, $confirmKey]) {
    $flowUuid = $flows[$key]['uuid'];
    $submitLabel = $language === 'ar' ? '🚀 إرسال الطلب' : '🚀 Send Request';
    $submit = $button($key . '_submit', $submitLabel, $confirmKey);
    $nodes = [$node($flowUuid, 'start', 'start', 'البداية')];
    $edges = [];
    $previous = 'start';
    foreach ($fields as $index => [$label, $questionText, $variable, $inputType]) {
        $questionId = 'q' . ($index + 1);
        $nodes[] = $question($flowUuid, $questionId, $label, $questionText, $variable, $inputType);
        $edges[] = $edge($key . '-q' . ($index + 1), $previous, $questionId, $previous === 'start' ? null : 'success|' . $fields[$index - 1][2]);
        $previous = $questionId;
    }
    $submitBody = $language === 'ar' ? "يرجى إدخال بياناتك لمتابعة طلب {$title}:" : "Please enter your details to proceed with your {$title} request:";
    $nodes[] = $node($flowUuid, 'submit', 'buttons', 'إرسال الطلب', $buttonFields($submitBody, ['button1' => $submitLabel]));
    $nodes[] = $node($flowUuid, 'to_confirm', 'portal', $submitLabel, $portalFields($submitLabel, $confirmKey, $submit['key']));
    $edges[] = $edge($key . '-submit', $previous, 'submit', 'success|' . $fields[count($fields) - 1][2]);
    $edges[] = $edge($key . '-confirm', 'submit', 'to_confirm', 'a');
    $flowBuilders[$key] = ['nodes' => $nodes, 'edges' => $edges];
    $allButtons[] = $submitLabel;

    $confirmUuid = $flows[$confirmKey]['uuid'];
    $back = $language === 'ar' ? $arBack($confirmKey) : $enBack($confirmKey);
    $confirmBody = $language === 'ar' ? 'تم استلام طلبك ✅ سيتواصل معك فريق رينتو قريبًا.' : 'Your request has been received ✅ The Rento team will contact you shortly.';
    $flowBuilders[$confirmKey] = [
        'nodes' => [
            $node($confirmUuid, 'start', 'start', 'البداية'),
            $node($confirmUuid, 'answer', 'text', 'تأكيد الطلب', ['type' => 'text', 'body' => $confirmBody]),
            $node($confirmUuid, 'back', 'buttons', 'العودة', $buttonFields('', ['button1' => $back['label']])),
            $node($confirmUuid, 'to_menu', 'portal', $back['label'], $portalFields($back['label'], $back['target'], $back['key'])),
        ],
        'edges' => [$edge($confirmKey . '-answer', 'start', 'answer'), $edge($confirmKey . '-back', 'answer', 'back'), $edge($confirmKey . '-menu', 'back', 'to_menu', 'a')],
    ];
    $allButtons[] = $back['label'];
}

// FAQ answer flows.
$faqBodies = [
    'ar_branches' => ['text' => "تنتشر فروع رينتو في كافة مناطق المملكة، وتشمل:\n📍 الرياض\n📍 المنطقة الغربية\n📍 المنطقة الشرقية\n📍 الخرج\nيمكنك زيارة أقرب فرع أو الاطلاع على مواقع الفروع والخرائط مباشرة عبر موقعنا الإلكتروني.", 'cta' => '🌐 زيارة الموقع', 'url' => 'https://example.com', 'back' => $arBack('ar_branches')],
    'en_branches' => ['text' => "Rento branches are spread across all regions of the Kingdom, including:\n📍 Riyadh\n📍 Western Region\n📍 Eastern Region\n📍 Al-Kharj\nYou can visit the nearest branch or check branch locations and maps directly via our website.", 'cta' => '🌐 Visit Website', 'url' => 'https://example.com', 'back' => $enBack('en_branches')],
    'ar_contact' => ['text' => "فريق خدمة العملاء متواجد لخدمتكم على مدار الساعة 🕐\nللتواصل المباشر، يمكنكم الاتصال على الرقم الموحد:\n920019993 📞", 'cta' => null, 'url' => null, 'back' => $arBack('ar_contact')],
    'en_contact' => ['text' => "Customer service team is available 24/7 to serve you 🕐\nFor direct contact, call our unified number:\n920019993 📞", 'cta' => null, 'url' => null, 'back' => $enBack('en_contact')],
    'ar_general' => ['text' => "متطلبات الاستئجار:\n• بطاقة هوية وطنية سارية أو هوية مقيم سارية.\n• رخصة قيادة سارية.\n• الحد الأدنى للعمر: 21 سنة.", 'cta' => null, 'url' => null, 'back' => $arBack('ar_general')],
    'en_general' => ['text' => "Rental Requirements:\n• Valid National ID or valid Iqama.\n• Valid driver's license.\n• Minimum age: 21 years old.", 'cta' => null, 'url' => null, 'back' => $enBack('en_general')],
];
foreach ($faqBodies as $key => $spec) {
    $language = str_starts_with($key, 'ar_') ? 'ar' : 'en';
    $uuid = $flows[$key]['uuid'];
    $nodes = [$node($uuid, 'start', 'start', 'البداية'), $node($uuid, 'answer', 'text', 'المعلومات', ['type' => 'text', 'body' => $spec['text']])];
    $edges = [$edge($key . '-answer', 'start', 'answer')];
    if ($spec['cta'] !== null) {
        $nodes[] = $node($uuid, 'cta', 'buttons', 'زيارة الموقع', [
            'type' => 'interactive buttons', 'headerType' => 'none', 'headerText' => '', 'headerMedia' => [],
            'body' => $spec['text'], 'footer' => '', 'buttonType' => 'cta_url',
            'buttons' => ['button1' => '', 'button2' => '', 'button3' => ''],
            'ctaUrlButton' => ['displayText' => $spec['cta'], 'url' => $spec['url']],
        ]);
        $edges[] = $edge($key . '-cta', 'answer', 'cta');
    }
    $back = $spec['back'];
    $nodes[] = $node($uuid, 'back', 'buttons', 'العودة', $buttonFields('', ['button1' => $back['label']]));
    $nodes[] = $node($uuid, 'to_menu', 'portal', $back['label'], $portalFields($back['label'], $back['target'], $back['key']));
    $edges[] = $edge($key . '-back', $spec['cta'] !== null ? 'cta' : 'answer', 'back');
    $edges[] = $edge($key . '-menu', 'back', 'to_menu', 'a');
    $flowBuilders[$key] = ['nodes' => $nodes, 'edges' => $edges];
    if ($spec['cta'] !== null) {
        $allButtons[] = $spec['cta'];
    }
    $allButtons[] = $back['label'];
}

foreach ($allButtons as $label) {
    if ($label !== '' && $labelLength($label) > $maxButtonLength) {
        throw new RuntimeException("Button length validation failed for {$label}.");
    }
}

DB::transaction(function () use (&$flows, &$triggers, $flowBuilders, $organizationId, $bundleId): void {
    foreach ($flows as $key => &$flow) {
        $flow['id'] = DB::table('flows')->insertGetId([
            'uuid' => $flow['uuid'], 'organization_id' => $organizationId, 'name' => $flow['name'],
            'description' => 'Rento Car Rental chatbot imported from the approved simulator.',
            'metadata' => json_encode(['nodes' => [], 'edges' => [], 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'active', 'is_builtin' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    unset($flow);

    foreach ($triggers as $key => &$trigger) {
        $trigger['id'] = DB::table('trigger_groups')->insertGetId([
            'uuid' => $trigger['uuid'], 'organization_id' => $organizationId, 'name' => $trigger['name'],
            'type' => 'new_message', 'keywords' => json_encode([$trigger['name']], JSON_UNESCAPED_UNICODE),
            'match_mode' => 'exact', 'ai_confidence' => 75, 'flow_id' => $flows[$trigger['target']]['id'],
            'priority' => 0, 'is_system' => 0, 'is_enabled' => 1, 'rate_limit_type' => 'none',
            'metadata' => json_encode(['source' => 'rento_simulator', 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    unset($trigger);

    foreach ($flows as $key => $flow) {
        $metadata = $flowBuilders[$key];
        foreach ($metadata['nodes'] as &$metadataNode) {
            if (($metadataNode['type'] ?? '') !== 'portal') {
                continue;
            }
            $fields = &$metadataNode['data']['metadata']['fields'];
            foreach ($flows as $targetFlow) {
                if (($fields['target_flow_uuid'] ?? '') === $targetFlow['uuid']) {
                    $fields['target_flow_id'] = $targetFlow['id'];
                    break;
                }
            }
            unset($fields);
        }
        unset($metadataNode);
        $metadata['import_bundle_id'] = $bundleId;
        $metadata['import_source_organization_id'] = $organizationId;
        DB::table('flows')->where('id', $flow['id'])->update([
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
});

echo json_encode([
    'organization_id' => $organizationId,
    'organization_name' => $organization->name,
    'flows' => count($flows),
    'triggers' => count($triggers),
    'bundle_id' => $bundleId,
    'start_flow_id' => $flows['start']['id'],
    'start_trigger' => '000',
    'max_button_length' => $maxButtonLength,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
