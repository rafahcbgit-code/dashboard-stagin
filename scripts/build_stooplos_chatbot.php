<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$organizationId = (int) ($argv[1] ?? 451);
$bundleId = 'stooplos-chatbot-v1';

$organization = DB::table('organizations')->where('id', $organizationId)->first();
if (!$organization) {
    throw new RuntimeException("Organization {$organizationId} was not found.");
}
if (DB::table('flows')->where('organization_id', $organizationId)->where('metadata', 'like', '%"import_bundle_id":"' . $bundleId . '"%')->exists()) {
    throw new RuntimeException("Bundle {$bundleId} is already installed for organization {$organizationId}.");
}

$flowNames = [
    'start' => 'Stop Loss | البداية',
    'services' => 'Stop Loss | قائمة الخدمات',
    'academy' => 'Stop Loss | Stop Loss Academy',
    'recommendations' => 'Stop Loss | قناة التوصيات',
    'live' => 'Stop Loss | التداول لايف',
    'copy' => 'Stop Loss | ناسخ الصفقات',
    'dawaniya' => 'Stop Loss | دوانية Stop Loss',
    'customer' => 'Stop Loss | خدمة العملاء والدعم',
    'reg_academy' => 'Stop Loss | تسجيل Academy',
    'academy_support' => 'Stop Loss | دعم Academy',
    'reg_recommendations' => 'Stop Loss | اشتراك قناة التوصيات',
    'recommendations_inquiry' => 'Stop Loss | استفسار قناة التوصيات',
    'live_payment' => 'Stop Loss | دفع مباشر',
    'live_seat' => 'Stop Loss | حجز مقعد لايف',
    'live_support' => 'Stop Loss | دعم التداول لايف',
    'copy_form' => 'Stop Loss | استمارة ناسخ الصفقات',
    'copy_support' => 'Stop Loss | دعم ناسخ الصفقات',
    'dawaniya_map' => 'Stop Loss | موقع الدوانية',
    'dawaniya_attendance' => 'Stop Loss | تأكيد حضور الدوانية',
];

$flows = [];
foreach ($flowNames as $key => $name) {
    $flows[$key] = ['uuid' => (string) Str::uuid(), 'name' => $name, 'id' => null];
}

$triggerTargets = [
    '000' => 'start',
    'عرض الخدمات 📋' => 'services',
    '1️⃣ Stop Loss Academy 🎓' => 'academy',
    '2️⃣ قناة التوصيات 📊' => 'recommendations',
    '3️⃣ التداول لايف 📈' => 'live',
    '4️⃣ ناسخ الصفقات 🔄' => 'copy',
    '5️⃣ دوانية Stop Loss ☕️' => 'dawaniya',
    '6️⃣ خدمة العملاء والدعم 💬' => 'customer',
    'التسجيل بالدورة 📝' => 'reg_academy',
    'التحدث مع الدعم 💬' => 'academy_support',
    'طلب الاشتراك 🚀' => 'reg_recommendations',
    'استفسار مخصص 💬' => 'recommendations_inquiry',
    'دفع مباشر 💳' => 'live_payment',
    'حجز مقعد لايف 🎟️' => 'live_seat',
    'الدعم الفني 💬' => 'live_support',
    'فتح استمارة الربط 📋 (Meta Flow)' => 'copy_form',
    'استفسار مع الدعم 💬' => 'copy_support',
    'موقع الدوانية 📍 (CTA)' => 'dawaniya_map',
    'تأكيد الحضور ☕' => 'dawaniya_attendance',
    'القائمة الرئيسية ↩️' => 'start',
    'العودة للقائمة الرئيسية ↩️' => 'start',
];

$triggers = [];
foreach ($triggerTargets as $keyword => $target) {
    $triggers[$keyword] = ['uuid' => (string) Str::uuid(), 'target' => $target, 'id' => null];
}

$node = static function (string $flowUuid, string $id, string $type, string $label, array $fields = [], array $data = []): array {
    $nodeData = ['uuid' => $flowUuid, 'title' => $label, 'metadata' => ['fields' => $fields]];
    if ($data) {
        $nodeData = array_replace_recursive($nodeData, $data);
    }
    return ['id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'data' => $nodeData];
};

$edge = static function (string $id, string $source, string $target, ?string $handle = null): array {
    $e = ['id' => $id, 'source' => $source, 'target' => $target];
    if ($handle !== null) $e['sourceHandle'] = $handle;
    return $e;
};

$buttonFields = static function (string $body, array $buttons): array {
    if ($body === '') {
        $body = (string) (array_values(array_filter($buttons))[0] ?? '');
    }
    return [
        'type' => 'interactive buttons', 'headerType' => 'none', 'headerText' => '', 'headerMedia' => [],
        'body' => $body, 'footer' => '', 'buttonType' => 'buttons',
        'buttons' => array_replace(['button1' => '', 'button2' => '', 'button3' => ''], $buttons),
        'ctaUrlButton' => ['displayText' => '', 'url' => ''],
    ];
};

$listFields = static function (string $body, array $rows): array {
    return [
        'type' => 'interactive list', 'headerType' => 'none', 'headerText' => '', 'headerMedia' => [],
        'body' => $body, 'footer' => '', 'buttonLabel' => 'اختر الخدمة المطلوبة 📋',
        'sections' => [['title' => 'الخدمات المتاحة', 'rows' => $rows]],
        'selection_variable' => 'selected_service', 'selection_mode' => 'replace',
    ];
};

$portal = static function (string $flowUuid, string $label, string $targetKey, array $flows, array $triggers): array {
    return [
        'type' => 'portal', 'target_flow_uuid' => $flows[$targetKey]['uuid'], 'target_flow_name' => $flows[$targetKey]['name'],
        'target_flow_id' => $flows[$targetKey]['id'], 'connection_mode' => 'with_trigger',
        'trigger_name' => $label, 'trigger_keyword' => $label, 'trigger_created' => true,
        'trigger_uuid' => $triggers[$label]['uuid'],
    ];
};

$buttonBranch = static function (string $flowUuid, string $nodeId, string $label, string $targetKey, array $flows, array $triggers) use ($node, $portal): array {
    return [$node($flowUuid, $nodeId, 'portal', $label, $portal($flowUuid, $label, $targetKey, $flows, $triggers))];
};

$question = static function (string $flowUuid, string $id, string $label, string $text, string $variable, string $inputType = 'text') use ($node): array {
    return $node($flowUuid, $id, 'question', $label, [
        'type' => 'question', 'question_text' => $text, 'variable_name' => $variable,
        'input_type' => $inputType, 'max_retries' => 2,
        'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
    ]);
};

$build = [];

$build['start'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    return [
        'nodes' => [
            $node($flows['start']['uuid'], 'start', 'start', 'البداية', ['type' => 'keywords', 'keywords' => '000']),
            $node($flows['start']['uuid'], 'welcome', 'buttons', 'عرض الخدمات', $buttonFields("أهلاً وسهلاً في **Stop Loss** 🖤💛 حياك الله، ويسعدنا نخدمك. خدماتنا المتوفرة حالياً: 1️⃣ **Stop Loss Academy** 🎓 دورات تعليمية وتدريبية في الأسواق المالية والاستثمار. 2️⃣ **قناة التوصيات** 📊 تحليلات ومتابعة للسوق + توصيات وأخبار وملخصات مستمرة. 3️⃣ **التداول لايف** 📈 جلسات تداول مباشرة ومتابعة السوق مع المحللين. 4️⃣ **ناسخ الصفقات** 🔄 خدمة نسخ الصفقات وإدارة آلية للصفقات حسب النظام. 5️⃣ **دوانية Stop Loss** ☕️ لقاءات، محاضرات، دورات حضورية وتداول لايف. 6️⃣ **خدمة العملاء والدعم** 💬 **Stop Loss** *نبني المستثمر قبل ما نبني المتداول*", ['button1' => 'عرض الخدمات 📋'])),
            $node($flows['start']['uuid'], 'to_services', 'portal', 'إلى قائمة الخدمات', $portal($flows['start']['uuid'], 'عرض الخدمات 📋', 'services', $flows, $triggers)),
        ],
        'edges' => [$edge('start-welcome', 'start', 'welcome'), $edge('welcome-services', 'welcome', 'to_services', 'a')],
    ];
};

$build['services'] = function () use ($flows, $triggers, $node, $listFields, $portal, $edge): array {
    $items = [
        ['1️⃣ Stop Loss Academy 🎓', 'دورات تعليمية وتدريبية في الأسواق المالية والاستثمار', 'academy'],
        ['2️⃣ قناة التوصيات 📊', 'تحليلات ومتابعة للسوق + توصيات وأخبار مستمرة', 'recommendations'],
        ['3️⃣ التداول لايف 📈', 'جلسات تداول مباشرة ومتابعة حية مع المحللين', 'live'],
        ['4️⃣ ناسخ الصفقات 🔄', 'خدمة نسخ الصفقات والإدارة الآلية المباشرة', 'copy'],
        ['5️⃣ دوانية Stop Loss ☕️', 'لقاءات، محاضرات، دورات حضورية وفعاليات', 'dawaniya'],
        ['6️⃣ خدمة العملاء والدعم 💬', 'التواصل المباشر مع فريق الدعم الفني', 'customer'],
    ];
    $nodes = [$node($flows['services']['uuid'], 'start', 'start', 'البداية'), $node($flows['services']['uuid'], 'list', 'list', 'اختر الخدمة المطلوبة', $listFields('اختر الخدمة المطلوبة 📋', array_map(fn($i) => ['id' => 'service_' . $i[2], 'title' => $i[0], 'description' => $i[1]], $items)))];
    $edges = [$edge('services-start', 'start', 'list')];
    foreach ($items as $idx => [$label, $description, $target]) {
        $id = 'to_' . $target;
        $nodes[] = $node($flows['services']['uuid'], $id, 'portal', $label, $portal($flows['services']['uuid'], $label, $target, $flows, $triggers));
        $edges[] = $edge('services-' . $target, 'list', $id, 'a0' . $idx);
    }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['academy'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $buttons = [['التسجيل بالدورة 📝', 'reg_academy'], ['التحدث مع الدعم 💬', 'academy_support'], ['القائمة الرئيسية ↩️', 'start']];
    $nodes = [$node($flows['academy']['uuid'], 'start', 'start', 'البداية'), $node($flows['academy']['uuid'], 'choices', 'buttons', 'خيارات Academy', $buttonFields("🎓 **Stop Loss Academy** دورات تعليمية وتدريبية شمولية احترافية في الأسواق المالية والاستثمار، مصممة لتأهيلك من الصفر وحتى المتاجرة المستقلة بثقة. 👇 يمكنك اختيار الإجراء المطلوب:", ['button1' => $buttons[0][0], 'button2' => $buttons[1][0], 'button3' => $buttons[2][0]]))];
    $edges = [$edge('academy-start', 'start', 'choices')];
    foreach ($buttons as $i => [$label, $target]) { $id = 'to_' . $i; $nodes[] = $node($flows['academy']['uuid'], $id, 'portal', $label, $portal($flows['academy']['uuid'], $label, $target, $flows, $triggers)); $edges[] = $edge('academy-' . $i, 'choices', $id, chr(97 + $i)); }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['recommendations'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $buttons = [['طلب الاشتراك 🚀', 'reg_recommendations'], ['استفسار مخصص 💬', 'recommendations_inquiry'], ['القائمة الرئيسية ↩️', 'start']];
    $nodes = [$node($flows['recommendations']['uuid'], 'start', 'start', 'البداية'), $node($flows['recommendations']['uuid'], 'choices', 'buttons', 'خيارات القناة', $buttonFields("📊 **قناة التوصيات والتحليلات** تحليلات دقيقة ومتابعة لحظية للسوق + توصيات يومية مدروسة مع قواعد صارمة لإدارة المخاطر، وأخبار وملخصات اقتصادية. 👇 اختر الخطوة المناسبة لك:", ['button1' => $buttons[0][0], 'button2' => $buttons[1][0], 'button3' => $buttons[2][0]]))];
    $edges = [$edge('recommendations-start', 'start', 'choices')];
    foreach ($buttons as $i => [$label, $target]) { $id = 'to_' . $i; $nodes[] = $node($flows['recommendations']['uuid'], $id, 'portal', $label, $portal($flows['recommendations']['uuid'], $label, $target, $flows, $triggers)); $edges[] = $edge('recommendations-' . $i, 'choices', $id, chr(97 + $i)); }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['live'] = function () use ($flows, $triggers, $node, $listFields, $portal, $edge): array {
    $items = [['دفع مباشر 💳', 'live_payment'], ['حجز مقعد لايف 🎟️', 'live_seat'], ['الدعم الفني 💬', 'live_support'], ['القائمة الرئيسية ↩️', 'start']];
    $rows = array_map(fn($i) => ['id' => 'live_' . $i[1], 'title' => $i[0], 'description' => ''], $items);
    $nodes = [$node($flows['live']['uuid'], 'start', 'start', 'البداية'), $node($flows['live']['uuid'], 'choices', 'list', 'خيارات التداول لايف', $listFields("📈 **التداول لايف** جلسات تداول حية ومباشرة لمتابعة تحركات الأسواق خطوة بخطوة مع نخبة من محللينا الماليين وتطبيق استراتيجيات التداول اللحظي. 👇 اختر الإجراء:", $rows))];
    $edges = [$edge('live-start', 'start', 'choices')];
    foreach ($items as $idx => [$label, $target]) { $id = 'to_' . $target; $nodes[] = $node($flows['live']['uuid'], $id, 'portal', $label, $portal($flows['live']['uuid'], $label, $target, $flows, $triggers)); $edges[] = $edge('live-' . $idx, 'choices', $id, 'a0' . $idx); }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['copy'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $buttons = [['فتح استمارة الربط 📋 (Meta Flow)', 'copy_form'], ['استفسار مع الدعم 💬', 'copy_support'], ['القائمة الرئيسية ↩️', 'start']];
    $nodes = [$node($flows['copy']['uuid'], 'start', 'start', 'البداية'), $node($flows['copy']['uuid'], 'choices', 'buttons', 'خيارات ناسخ الصفقات', $buttonFields("🔄 **خدمة ناسخ الصفقات** خدمة الربط والنسخ الآلي المباشر للصفقات وإدارة المحافظ وفق نظام آلي متطور يحافظ على أمان حسابك. 👇 يمكنك البدء بتعبئة الاستمارة المباشرة:", ['button1' => $buttons[0][0], 'button2' => $buttons[1][0], 'button3' => $buttons[2][0]]))];
    $edges = [$edge('copy-start', 'start', 'choices')];
    foreach ($buttons as $i => [$label, $target]) { $id = 'to_' . $i; $nodes[] = $node($flows['copy']['uuid'], $id, 'portal', $label, $portal($flows['copy']['uuid'], $label, $target, $flows, $triggers)); $edges[] = $edge('copy-' . $i, 'choices', $id, chr(97 + $i)); }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['dawaniya'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $buttons = [['موقع الدوانية 📍 (CTA)', 'dawaniya_map'], ['تأكيد الحضور ☕', 'dawaniya_attendance'], ['القائمة الرئيسية ↩️', 'start']];
    $nodes = [$node($flows['dawaniya']['uuid'], 'start', 'start', 'البداية'), $node($flows['dawaniya']['uuid'], 'choices', 'buttons', 'خيارات الدوانية', $buttonFields("☕️ **دوانية Stop Loss** لقاءات دورية، محاضرات ثرية، دورات حضورية، وتداول لايف في بيئة استثمارية تجمعك مع مختصين ومحللين. 👇 تفاصيل الوصول والتأكيد:", ['button1' => $buttons[0][0], 'button2' => $buttons[1][0], 'button3' => $buttons[2][0]]))];
    $edges = [$edge('dawaniya-start', 'start', 'choices')];
    foreach ($buttons as $i => [$label, $target]) { $id = 'to_' . $i; $nodes[] = $node($flows['dawaniya']['uuid'], $id, 'portal', $label, $portal($flows['dawaniya']['uuid'], $label, $target, $flows, $triggers)); $edges[] = $edge('dawaniya-' . $i, 'choices', $id, chr(97 + $i)); }
    return ['nodes' => $nodes, 'edges' => $edges];
};

$build['customer'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    return ['nodes' => [$node($flows['customer']['uuid'], 'start', 'start', 'البداية'), $node($flows['customer']['uuid'], 'answer', 'text', 'خدمة العملاء', ['type' => 'text', 'body' => "💬 **خدمة العملاء والدعم المباشر** يسعدنا تواصلك معنا! نرجو كتابة كافة تفاصيل استفسارك أو رقم طلبك، وسيقوم أحد ممثلي فريق الدعم بالرد عليك بأسرع وقت. *ساعات العمل: من الأحد إلى الخميس (9:00 ص - 9:00 م)*"]), $node($flows['customer']['uuid'], 'back', 'buttons', 'القائمة الرئيسية', $buttonFields('', ['button1' => 'القائمة الرئيسية ↩️'])), $node($flows['customer']['uuid'], 'to_start', 'portal', 'العودة للقائمة الرئيسية', $portal($flows['customer']['uuid'], 'القائمة الرئيسية ↩️', 'start', $flows, $triggers))], 'edges' => [$edge('customer-answer', 'start', 'answer'), $edge('customer-back', 'answer', 'back'), $edge('customer-start', 'back', 'to_start', 'a')]];
};

$registration = static function (string $key, string $serviceLabel) use ($flows, $triggers, $node, $question, $buttonFields, $portal, $edge): array {
    $flowUuid = $flows[$key]['uuid'];
    return ['nodes' => [$node($flowUuid, 'start', 'start', 'البداية'), $question($flowUuid, 'q_name', 'الاسم الكامل', 'الاسم الكامل', 'full_name'), $question($flowUuid, 'q_phone', 'رقم الواتساب', 'رقم الواتساب', 'whatsapp', 'phone'), $question($flowUuid, 'q_experience', 'خبرتك في التداول والأسواق', 'خبرتك في التداول والأسواق: مبتدئ (أرغب بالتعلم من الصفر) / متوسط (لدي خبرة وقواعد أساسية) / محترف (أبحث عن أدوات وتوصيات)', 'experience'), $node($flowUuid, 'done', 'text', 'تأكيد الطلب', ['type' => 'text', 'body' => 'تم استلام طلبك ✅ وسيتم التواصل معك مباشرة من فريق Stop Loss 🖤💛']), $node($flowUuid, 'back', 'buttons', 'العودة للقائمة الرئيسية', $buttonFields('', ['button1' => 'العودة للقائمة الرئيسية ↩️'])), $node($flowUuid, 'to_start', 'portal', 'العودة للقائمة الرئيسية', $portal($flowUuid, 'العودة للقائمة الرئيسية ↩️', 'start', $flows, $triggers))], 'edges' => [$edge($key . '-q1', 'start', 'q_name'), $edge($key . '-q2', 'q_name', 'q_phone', 'success|q_name'), $edge($key . '-q3', 'q_phone', 'q_experience', 'success|q_phone'), $edge($key . '-done', 'q_experience', 'done', 'success|q_experience'), $edge($key . '-back', 'done', 'back'), $edge($key . '-start', 'back', 'to_start', 'a')]];
};

$build['reg_academy'] = fn () => $registration('reg_academy', 'Stop Loss Academy 🎓');
$build['reg_recommendations'] = fn () => $registration('reg_recommendations', 'قناة التوصيات 📊');
$build['copy_form'] = fn () => $registration('copy_form', 'ناسخ الصفقات 🔄');
$build['dawaniya_attendance'] = fn () => $registration('dawaniya_attendance', 'دوانية Stop Loss ☕️');

$simpleReply = static function (string $key, string $body, string $buttonLabel = 'العودة للقائمة الرئيسية ↩️') use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $uuid = $flows[$key]['uuid'];
    return ['nodes' => [$node($uuid, 'start', 'start', 'البداية'), $node($uuid, 'answer', 'text', 'الرد', ['type' => 'text', 'body' => $body]), $node($uuid, 'back', 'buttons', 'العودة للقائمة الرئيسية', $buttonFields('', ['button1' => $buttonLabel])), $node($uuid, 'to_start', 'portal', 'العودة للقائمة الرئيسية', $portal($uuid, $buttonLabel, 'start', $flows, $triggers))], 'edges' => [$edge($key . '-answer', 'start', 'answer'), $edge($key . '-back', 'answer', 'back'), $edge($key . '-start', 'back', 'to_start', 'a')]];
};

$build['academy_support'] = fn () => $simpleReply('academy_support', 'تم استلام طلبك بخصوص **"التحدث مع الدعم 💬"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');
$build['recommendations_inquiry'] = fn () => $simpleReply('recommendations_inquiry', 'تم استلام طلبك بخصوص **"استفسار عن قناة التوصيات 📊"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');
$build['live_payment'] = fn () => $simpleReply('live_payment', 'تم استلام طلبك بخصوص **"دفع مباشر 💳"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');
$build['live_seat'] = fn () => $simpleReply('live_seat', 'تم استلام طلبك بخصوص **"حجز مقعد لايف 🎟️"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');
$build['live_support'] = fn () => $simpleReply('live_support', 'تم استلام طلبك بخصوص **"الدعم الفني 💬"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');
$build['copy_support'] = fn () => $simpleReply('copy_support', 'تم استلام طلبك بخصوص **"استفسار مع الدعم 💬"** ✅ قام النظام بتحويل طلبك إلى ممثل خدمة العملاء المختص بـ Stop Loss 🖤💛 وسيتم التواصل معك مباشرة.');

$build['dawaniya_map'] = function () use ($flows, $triggers, $node, $buttonFields, $portal, $edge): array {
    $uuid = $flows['dawaniya_map']['uuid'];
    return ['nodes' => [$node($uuid, 'start', 'start', 'البداية'), $node($uuid, 'cta', 'buttons', 'موقع الدوانية', ['type' => 'interactive buttons', 'headerType' => 'none', 'body' => 'اضغط الزر لفتح موقع الدوانية على الخريطة.', 'footer' => '', 'buttonType' => 'cta_url', 'buttons' => ['button1' => '', 'button2' => '', 'button3' => ''], 'ctaUrlButton' => ['displayText' => 'موقع الدوانية 📍 (CTA)', 'url' => 'https://maps.google.com']]), $node($uuid, 'back', 'buttons', 'العودة للقائمة الرئيسية', $buttonFields('', ['button1' => 'العودة للقائمة الرئيسية ↩️'])), $node($uuid, 'to_start', 'portal', 'العودة للقائمة الرئيسية', $portal($uuid, 'العودة للقائمة الرئيسية ↩️', 'start', $flows, $triggers))], 'edges' => [$edge('map-cta', 'start', 'cta'), $edge('map-back', 'cta', 'back'), $edge('map-start', 'back', 'to_start', 'a')]];
};

DB::transaction(function () use (&$flows, &$triggers, $organizationId, $bundleId, $build): void {
    foreach ($flows as $key => &$flow) {
        $flow['id'] = DB::table('flows')->insertGetId(['uuid' => $flow['uuid'], 'organization_id' => $organizationId, 'name' => $flow['name'], 'description' => 'Stop Loss chatbot imported from the approved simulator.', 'metadata' => json_encode(['nodes' => [], 'edges' => [], 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'status' => 'active', 'is_builtin' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    unset($flow);
    foreach ($triggers as $keyword => &$trigger) {
        $target = $flows[$trigger['target']];
        $trigger['id'] = DB::table('trigger_groups')->insertGetId(['uuid' => $trigger['uuid'], 'organization_id' => $organizationId, 'name' => $keyword, 'type' => 'new_message', 'keywords' => json_encode([$keyword], JSON_UNESCAPED_UNICODE), 'match_mode' => 'exact', 'ai_confidence' => 75, 'flow_id' => $target['id'], 'priority' => 0, 'is_system' => 0, 'is_enabled' => 1, 'rate_limit_type' => 'none', 'metadata' => json_encode(['source' => 'stooplos_simulator', 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => now(), 'updated_at' => now()]);
    }
    unset($trigger);
    foreach ($flows as $key => &$flow) {
        $metadata = $build[$key]();
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
        DB::table('flows')->where('id', $flow['id'])->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }
    unset($flow);
});

echo json_encode(['organization_id' => $organizationId, 'flows' => count($flows), 'triggers' => count($triggers), 'bundle_id' => $bundleId, 'start_flow_id' => $flows['start']['id']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
