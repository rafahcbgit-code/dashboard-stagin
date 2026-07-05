<?php

use App\Models\Contact;
use App\Support\Filters\ContactFilter;
use Illuminate\Http\Request;

it('uses indexed phone candidates for full phone searches', function () {
    $query = Contact::query()->select('contacts.*');

    (new ContactFilter())->apply(
        $query,
        Request::create('/contacts', 'GET', ['search' => '+966 56 454 5214'])
    );

    expect($query->toSql())
        ->toContain('`contacts`.`phone` in')
        ->not->toContain('contacts.first_name')
        ->not->toContain('REGEXP_REPLACE')
        ->not->toContain('CONVERT(0xC2A0 USING utf8mb4)')
        ->not->toContain('CHAR(160)');

    expect($query->getBindings())
        ->toContain('966564545214')
        ->toContain('+966564545214')
        ->not->toContain('%966564545214%');
});

it('allows partial phone searches without regexp replacements', function () {
    $query = Contact::query()->select('contacts.*');

    (new ContactFilter())->apply(
        $query,
        Request::create('/contacts', 'GET', ['search' => '4545214'])
    );

    expect($query->toSql())
        ->toContain('`contacts`.`phone` like ?')
        ->not->toContain('REGEXP_REPLACE');

    expect($query->getBindings())->toContain('%4545214%');
});

it('extracts usable phone fragments from pasted urls', function () {
    $query = Contact::query()->select('contacts.*');

    (new ContactFilter())->apply(
        $query,
        Request::create('/contacts', 'GET', ['search' => 'http://localhost:8000/contacts?search=564545214?search=564545214'])
    );

    expect($query->getBindings())->toContain('%564545214%');
});

it('keeps full name search bindings intact', function () {
    $query = Contact::query()->select('contacts.*');

    (new ContactFilter())->apply(
        $query,
        Request::create('/contacts', 'GET', ['search' => 'Lina Haddad'])
    );

    expect($query->getBindings())->toContain('%Lina Haddad%');
});
