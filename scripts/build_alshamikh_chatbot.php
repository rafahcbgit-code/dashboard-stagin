<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$organizationId = (int) ($argv[1] ?? 449);
$organization = DB::table('organizations')->where('id', $organizationId)->first();

if (!$organization) {
    throw new RuntimeException("Organization {$organizationId} was not found.");
}

$bundleId = 'alshamikh-rehab-chatbot-v1';
$existing = DB::table('flows')
    ->where('organization_id', $organizationId)
    ->where('metadata', 'like', '%"import_bundle_id":"' . $bundleId . '"%')
    ->exists();

if ($existing) {
    throw new RuntimeException("Bundle {$bundleId} is already installed for organization {$organizationId}.");
}

$uuid = static fn (): string => (string) Illuminate\Support\Str::uuid();

$node = static function (string $id, string $type, string $label, array $fields = [], array $extra = []): array {
    $data = ['metadata' => ['fields' => $fields]];
    if (isset($extra['data'])) {
        $data = array_replace_recursive($data, $extra['data']);
        unset($extra['data']);
    }

    return array_merge([
        'id' => $id,
        'type' => $type,
        'label' => $label,
        'position' => ['x' => 0, 'y' => 0],
        'data' => $data,
    ], $extra);
};

$edge = static function (string $id, string $source, string $target, ?string $sourceHandle = null): array {
    $item = ['id' => $id, 'source' => $source, 'target' => $target];
    if ($sourceHandle !== null) {
        $item['sourceHandle'] = $sourceHandle;
    }
    return $item;
};

$buttonFields = static function (string $body, array $buttons): array {
    return [
        'type' => 'interactive buttons',
        'headerType' => 'none',
        'headerText' => '',
        'headerMedia' => [],
        'body' => $body,
        'footer' => '',
        'buttonType' => 'buttons',
        'buttons' => array_replace(['button1' => '', 'button2' => '', 'button3' => ''], $buttons),
        'ctaUrlButton' => ['displayText' => '', 'url' => ''],
    ];
};

$portalFields = static function (string $targetUuid, string $targetName, int $targetId): array {
    return [
        'type' => 'portal',
        'target_flow_uuid' => $targetUuid,
        'target_flow_name' => $targetName,
        'target_flow_id' => $targetId,
        'connection_mode' => 'simple',
    ];
};

$flowSpecs = [];
$addFlow = static function (string $key, string $name, callable $builder) use (&$flowSpecs, $uuid): void {
    $flowSpecs[$key] = [
        'uuid' => $uuid(),
        'name' => $name,
        'build' => $builder,
    ];
};

$addFlow('start', 'مركز الشامخ | البداية', static function (array $f) use ($node, $edge, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية', ['type' => 'keywords', 'keywords' => '000']),
        $node('menu', 'buttons', 'القائمة الرئيسية', $buttonFields('اهلا بك في مركز الشامخ يرجى إخبارنا ما ترغب به', [
            'button1' => 'حجز موعد',
            'button2' => 'لدي استفسار',
        ])),
        $node('to_booking', 'portal', 'إلى حجز موعد', $portalFields($f['booking']['uuid'], $f['booking']['name'], $f['booking']['id'] ?? 0)),
        $node('to_inquiry', 'portal', 'إلى الاستفسارات', $portalFields($f['inquiry']['uuid'], $f['inquiry']['name'], $f['inquiry']['id'] ?? 0)),
    ];
});

$addFlow('booking', 'مركز الشامخ | حجز موعد', static function (array $f) use ($node, $edge, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية'),
        $node('choices', 'buttons', 'خيارات الحجز', $buttonFields('حجز موعد', [
            'button1' => 'كشف',
            'button2' => 'جلسة علاج',
            'button3' => '↩️ القائمة الرئيسية',
        ])),
        $node('to_exam', 'portal', 'إلى الكشف', $portalFields($f['exam']['uuid'], $f['exam']['name'], $f['exam']['id'] ?? 0)),
        $node('to_therapy', 'portal', 'إلى جلسة علاج', $portalFields($f['therapy']['uuid'], $f['therapy']['name'], $f['therapy']['id'] ?? 0)),
        $node('to_start', 'portal', 'إلى القائمة الرئيسية', $portalFields($f['start']['uuid'], $f['start']['name'], $f['start']['id'] ?? 0)),
    ];
});

$addFlow('inquiry', 'مركز الشامخ | الاستفسارات', static function (array $f) use ($node, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية'),
        $node('choices', 'buttons', 'خيارات الاستفسار', $buttonFields('لدي استفسار', [
            'button1' => '1. مواعيد العمل',
            'button2' => '2. موقع المركز',
            'button3' => '3. التواصل مع ممثل الخدمة',
        ])),
        $node('to_hours', 'portal', 'مواعيد العمل', $portalFields($f['hours']['uuid'], $f['hours']['name'], $f['hours']['id'] ?? 0)),
        $node('to_location', 'portal', 'موقع المركز', $portalFields($f['location']['uuid'], $f['location']['name'], $f['location']['id'] ?? 0)),
        $node('to_service', 'portal', 'التواصل مع ممثل الخدمة', $portalFields($f['service']['uuid'], $f['service']['name'], $f['service']['id'] ?? 0)),
        $node('to_start', 'portal', 'إلى القائمة الرئيسية', $portalFields($f['start']['uuid'], $f['start']['name'], $f['start']['id'] ?? 0)),
    ];
});

$addFlow('exam', 'مركز الشامخ | كشف', static function (array $f) use ($node, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية'),
        $node('q_name', 'question', 'الإسم الكامل', [
            'type' => 'question', 'question_text' => '1. الإسم الكامل', 'variable_name' => 'full_name',
            'input_type' => 'text', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('q_phone', 'question', 'رقم الهاتف للتواصل', [
            'type' => 'question', 'question_text' => '2. رقم الهاتف للتواصل', 'variable_name' => 'phone',
            'input_type' => 'phone', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('q_notes', 'question', 'الملاحظات الإضافية', [
            'type' => 'question', 'question_text' => '3. الملاحظات الإضافية', 'variable_name' => 'notes',
            'input_type' => 'text', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('done', 'text', 'تأكيد الكشف', ['type' => 'text', 'body' => 'وسيقوم ممثل الخدمة بالتواصل معكم']),
        $node('back', 'buttons', 'عودة للقائمة الرئيسية', $buttonFields('↩️ القائمة الرئيسية', ['button1' => '↩️ القائمة الرئيسية'])),
        $node('to_start', 'portal', 'إلى القائمة الرئيسية', $portalFields($f['start']['uuid'], $f['start']['name'], $f['start']['id'] ?? 0)),
    ];
});

$addFlow('therapy', 'مركز الشامخ | جلسة علاج', static function (array $f) use ($node, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية'),
        $node('q_specialist', 'question', 'اسم الأخصائي', [
            'type' => 'question', 'question_text' => '1. كتابة إسم الأخصائي المطلوب ان علم', 'variable_name' => 'specialist',
            'input_type' => 'text', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('q_name', 'question', 'الإسم الكامل', [
            'type' => 'question', 'question_text' => '2. الإسم الكامل', 'variable_name' => 'full_name',
            'input_type' => 'text', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('q_phone', 'question', 'رقم التواصل', [
            'type' => 'question', 'question_text' => '3. رقم التواصل', 'variable_name' => 'phone',
            'input_type' => 'phone', 'max_retries' => 2, 'retry_message' => 'القيمة المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ]),
        $node('done', 'text', 'تأكيد الجلسة', ['type' => 'text', 'body' => 'سيقوم ممثل الخدمة بالتواصل معكم']),
        $node('back', 'buttons', 'عودة للقائمة الرئيسية', $buttonFields('↩️ القائمة الرئيسية', ['button1' => '↩️ القائمة الرئيسية'])),
        $node('to_start', 'portal', 'إلى القائمة الرئيسية', $portalFields($f['start']['uuid'], $f['start']['name'], $f['start']['id'] ?? 0)),
    ];
});

$simpleInfo = static function (string $key, string $name, string $body, array $f) use ($node, $buttonFields, $portalFields): array {
    return [
        $node('start', 'start', 'البداية'),
        $node('answer', 'text', $name, ['type' => 'text', 'body' => $body]),
        $node('back', 'buttons', 'عودة للقائمة الرئيسية', $buttonFields('↩️ القائمة الرئيسية', ['button1' => '↩️ القائمة الرئيسية'])),
        $node('to_start', 'portal', 'إلى القائمة الرئيسية', $portalFields($f['start']['uuid'], $f['start']['name'], $f['start']['id'] ?? 0)),
    ];
};

$addFlow('hours', 'مركز الشامخ | مواعيد العمل', fn (array $f) => $simpleInfo('hours', 'مواعيد العمل', 'ساعات الدوام في المركز من الاحد الى الخميس من الساعة ٩:٠٠ ص الى الساعة ٩:٠٠ م السبت من الساعة ١:٠٠ م إلى الساعة ٩:٠٠ م الجمعة إجازة المركز', $f));
$addFlow('location', 'مركز الشامخ | موقع المركز', fn (array $f) => $simpleInfo('location', 'موقع المركز', 'المدينة المنورة حي الأزهري - جوار وفاء طلبة', $f));
$addFlow('service', 'مركز الشامخ | التواصل مع ممثل الخدمة', fn (array $f) => $simpleInfo('service', 'التواصل مع ممثل الخدمة', 'سيقوم ممثل الخدمة بالتواصل معكم', $f));

// Insert flows first so every portal can store the real auto-increment id.
DB::transaction(function () use (&$flowSpecs, $organizationId, $bundleId, $node, $edge): void {
    foreach ($flowSpecs as $key => &$spec) {
        $spec['id'] = DB::table('flows')->insertGetId([
            'uuid' => $spec['uuid'],
            'organization_id' => $organizationId,
            'name' => $spec['name'],
            'description' => 'Chatbot flow imported from the approved مركز الشامخ simulator.',
            'metadata' => json_encode(['nodes' => [], 'edges' => [], 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'active',
            'is_builtin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    unset($spec);

    foreach ($flowSpecs as $key => &$spec) {
        $nodes = $spec['build']($flowSpecs);
        $nodeIds = array_column($nodes, 'id');
        $edges = [];

        if ($key === 'start') {
            $edges = [$edge('start-menu', 'start', 'menu'), $edge('menu-booking', 'menu', 'to_booking', 'a'), $edge('menu-inquiry', 'menu', 'to_inquiry', 'b')];
        } elseif ($key === 'booking') {
            $edges = [$edge('booking-choices', 'start', 'choices'), $edge('booking-exam', 'choices', 'to_exam', 'a'), $edge('booking-therapy', 'choices', 'to_therapy', 'b'), $edge('booking-start', 'choices', 'to_start', 'c')];
        } elseif ($key === 'inquiry') {
            $edges = [$edge('inquiry-choices', 'start', 'choices'), $edge('inquiry-hours', 'choices', 'to_hours', 'a'), $edge('inquiry-location', 'choices', 'to_location', 'b'), $edge('inquiry-service', 'choices', 'to_service', 'c')];
        } elseif ($key === 'exam') {
            $edges = [$edge('exam-q1', 'start', 'q_name'), $edge('exam-q2', 'q_name', 'q_phone', 'success|q_name'), $edge('exam-q3', 'q_phone', 'q_notes', 'success|q_phone'), $edge('exam-done', 'q_notes', 'done', 'success|q_notes'), $edge('exam-back', 'done', 'back'), $edge('exam-start', 'back', 'to_start', 'a')];
        } elseif ($key === 'therapy') {
            $edges = [$edge('therapy-q1', 'start', 'q_specialist'), $edge('therapy-q2', 'q_specialist', 'q_name', 'success|q_specialist'), $edge('therapy-q3', 'q_name', 'q_phone', 'success|q_name'), $edge('therapy-done', 'q_phone', 'done', 'success|q_phone'), $edge('therapy-back', 'done', 'back'), $edge('therapy-start', 'back', 'to_start', 'a')];
        } else {
            $edges = [$edge($key . '-answer', 'start', 'answer'), $edge($key . '-back', 'answer', 'back'), $edge($key . '-start', 'back', 'to_start', 'a')];
        }

        $metadata = ['nodes' => $nodes, 'edges' => $edges, 'import_bundle_id' => $bundleId, 'source_organization_name' => 'مركز الشامخ للتأهيل'];
        DB::table('flows')->where('id', $spec['id'])->update([
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
    unset($spec);

    $start = $flowSpecs['start'];
    DB::table('trigger_groups')->insert([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'organization_id' => $organizationId,
        'name' => '000 | مركز الشامخ للتأهيل',
        'type' => 'new_message',
        'keywords' => json_encode(['000'], JSON_UNESCAPED_UNICODE),
        'match_mode' => 'exact',
        'ai_confidence' => 75,
        'flow_id' => $start['id'],
        'priority' => 0,
        'is_system' => 0,
        'is_enabled' => 1,
        'rate_limit_type' => 'none',
        'metadata' => json_encode(['source' => 'alshamikh_simulator', 'import_bundle_id' => $bundleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

$result = [];
foreach ($flowSpecs as $key => $spec) {
    $result['flows'][$key] = ['id' => $spec['id'], 'uuid' => $spec['uuid'], 'name' => $spec['name']];
}
$result['organization_id'] = $organizationId;
$result['bundle_id'] = $bundleId;
$result['trigger'] = '000';
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
