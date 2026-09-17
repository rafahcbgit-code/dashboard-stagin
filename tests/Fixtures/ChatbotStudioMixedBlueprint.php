<?php

$screen = static fn ($key, $name, $message, $choices = [], $cta = null) => [
    'key' => $key, 'name' => $name, 'messages' => [$message], 'questions' => [],
    'choices' => $choices, 'list_label' => null, 'cta' => $cta, 'next' => null,
];
$choice = static fn ($label, $target) => ['label' => $label, 'target' => $target];
$screens = [$screen('NODE_LANG', 'Language', "أهلاً بك\nPlease select a language", [
    $choice('العربية', 'NODE_01'), $choice('English', 'NODE_01_EN'),
])];
foreach (['' => true, '_EN' => false] as $suffix => $arabic) {
    $root = 'NODE_01'.$suffix;
    $screens[] = $screen($root, $arabic ? 'القائمة الرئيسية' : 'Main menu', $arabic ? 'اختر الخدمة المطلوبة.' : 'Choose your service.', [
        $choice($arabic ? 'المتجر' : 'Store', 'NODE_03'.$suffix),
        $choice($arabic ? 'فرع الرياض' : 'Riyadh', 'NODE_06'.$suffix),
        $choice($arabic ? 'فرع جدة' : 'Jeddah', 'NODE_07'.$suffix),
        $choice($arabic ? 'اتصل بنا' : 'Contact', 'NODE_32'.$suffix),
    ]);
    $screens[] = $screen('NODE_03'.$suffix, $arabic ? 'المتجر الإلكتروني' : 'Online store',
        $arabic ? "زيارة المتجر\nhttps://example.org/store" : "Visit the store\nhttps://example.org/store",
        [$choice($arabic ? 'العودة' : 'Back', $root)], ['label' => $arabic ? 'تصفح المتجر الإلكتروني' : 'Visit our online store', 'url' => 'https://example.org/store']);
    foreach (['06' => 'Riyadh', '07' => 'Jeddah'] as $number => $city) {
        $key = 'NODE_'.$number.$suffix;
        $screens[] = $screen($key, $city, 'Original branch details: '.$key, [
            $choice($arabic ? 'تواصل واتساب' : 'WhatsApp', $key.'_WA'),
            $choice($arabic ? 'العودة' : 'Back', $root),
        ]);
        $screens[] = $screen($key.'_WA', $city.' contact', 'Original WhatsApp message: '.$key, [
            $choice($arabic ? 'العودة' : 'Back', $key),
        ], ['label' => $arabic ? 'تواصل واتساب' : 'WhatsApp', 'url' => 'https://example.org/contact/'.$key]);
    }
    $contact = $screen('NODE_32'.$suffix, $arabic ? 'التواصل' : 'Admissions', 'Original contact body: '.$suffix,
        [$choice($arabic ? 'العودة' : 'Back', $root)],
        ['label' => $arabic ? 'التحدث مع موظف' : '👤 Talk to Admissions', 'url' => 'https://example.org/contact']);
    $contact['messages'] = ['Original introductory message: '.$suffix, ...$contact['messages']];
    $contact['questions'][] = ['text' => $arabic ? 'الاسم؟' : 'Your name?', 'variable' => 'name'.$suffix, 'input_type' => 'text', 'retry_message' => 'Try again'];
    $screens[] = $contact;
}

return ['name' => 'Mixed bilingual fixture', 'start' => 'NODE_LANG', 'trigger' => '000', 'issues' => [], 'screens' => $screens];
