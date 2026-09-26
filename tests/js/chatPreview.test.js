import test from 'node:test';
import assert from 'node:assert/strict';
import { documentExtension, parseChatMetadata } from '../../resources/js/Components/ChatComponents/chatPreview.js';

test('a document without a media relation still has a safe preview', () => {
  const chat = {
    media: null,
    metadata: JSON.stringify({ type: 'document', document: { mime_type: 'application/pdf' } }),
  };

  assert.equal(documentExtension(chat), 'PDF');
  assert.equal(documentExtension({ media: null, metadata: '{"type":"document"}' }), null);
});

test('the linked media type keeps precedence for existing document previews', () => {
  const chat = {
    media: { type: 'application/msword' },
    metadata: { type: 'document', media: { mime_type: 'application/pdf' } },
  };

  assert.equal(documentExtension(chat), 'DOC');
});

test('invalid or null metadata cannot break preview parsing', () => {
  for (const metadata of [null, 'null', '{invalid', '[]', '"text"']) {
    assert.deepEqual(parseChatMetadata(metadata), {});
  }
});
