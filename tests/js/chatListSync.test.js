import test from 'node:test';
import assert from 'node:assert/strict';
import { chatListFilterKey, chatListRequestParams, preserveChatListPage } from '../../resources/js/Pages/User/Chat/chatListSync.js';

test('a leader on page 14 keeps its rows without changing the broadcast result', () => {
  const response = { data: [{ id: 'page-one' }], meta: { current_page: 1, last_page: 20 } };
  const currentRows = {
    data: new Proxy([{ id: 'page-fourteen' }], {}),
    meta: { current_page: 14 },
  };

  const displayed = preserveChatListPage(response, currentRows);

  assert.equal(displayed.meta.current_page, 14);
  assert.equal(displayed.data[0].id, 'page-fourteen');
  assert.equal(response.meta.current_page, 1);
  assert.equal(response.data[0].id, 'page-one');
  assert.deepEqual(structuredClone(response), response);
  assert.throws(() => structuredClone(displayed), { name: 'DataCloneError' });
});

test('a page-one tab receives page-one rows from the same response', () => {
  const response = { data: [{ id: 'new-chat' }], meta: { current_page: 1 } };
  const currentRows = { data: [{ id: 'old-chat' }], meta: { current_page: 1 } };

  const displayed = preserveChatListPage(response, currentRows);

  assert.equal(displayed.meta.current_page, 1);
  assert.deepEqual(displayed.data, response.data);
});

test('broadcasts apply only to tabs with matching filters and sort order', () => {
  const requested = { search: 'Ali', labels: '2,3', status: 'all', awaiting_reply: 1 };
  const sameView = { search: 'Ali', labels: '2,3', status: 'all', awaiting_reply: '1' };
  const otherView = { ...sameView, labels: '4' };

  assert.equal(chatListFilterKey(requested, 'desc'), chatListFilterKey(sameView, 'desc'));
  assert.notEqual(chatListFilterKey(requested, 'desc'), chatListFilterKey(otherView, 'desc'));
  assert.notEqual(chatListFilterKey(requested, 'desc'), chatListFilterKey(sameView, 'asc'));
});

test('a direct filtered page load refreshes using its displayed filters', () => {
  const filters = {
    page: '14', search: 'Ali', labels: '2,3', assigned_to: '7',
    status: 'unread', awaiting_reply: 'true',
  };

  assert.deepEqual(chatListRequestParams(filters), {
    search: 'Ali', labels: '2,3', assigned_to: '7', status: 'unread', awaiting_reply: 1,
  });
  assert.equal(chatListFilterKey(filters), chatListFilterKey(chatListRequestParams(filters)));
  assert.deepEqual(chatListRequestParams({ awaiting_reply: 'on' }), { awaiting_reply: 1 });
});
