import { decodeMetaUnicode } from '../resources/js/lib/embeddedSignupHelpers.js';

const tests = [
    {
        label: 'decodes escaped unicode',
        input: '\u0634\u0643\u0631\u0627',
        expected: 'شكرا',
    },
    {
        label: 'leaves non-string untouched',
        input: 123,
        expected: 123,
    },
];

for (const test of tests) {
    const actual = decodeMetaUnicode(test.input);

    if (actual !== test.expected) {
        console.error(`Test "${test.label}" failed: expected "${test.expected}", got "${actual}"`);
        process.exitCode = 1;
        break;
    }
}

if (process.exitCode !== 1) {
    console.log('All embedded signup helper tests pass.');
}
