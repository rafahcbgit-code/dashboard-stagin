const filterValue = (value) => value == null ? '' : String(value);
const awaitingReplyEnabled = (value) => [true, 1, '1', 'true', 'on', 'yes'].includes(value);

export const chatListRequestParams = (filters = {}) => {
  const params = {};
  for (const key of ['search', 'labels', 'assigned_to', 'status']) {
    if (filters[key]) params[key] = filters[key];
  }
  if (awaitingReplyEnabled(filters.awaiting_reply)) params.awaiting_reply = 1;
  return params;
};

export const chatListFilterKey = (filters = {}, sortDirection = 'desc') => JSON.stringify([
  filterValue(filters.search),
  filterValue(filters.labels),
  filterValue(filters.assigned_to),
  filterValue(filters.status || 'all'),
  awaitingReplyEnabled(filters.awaiting_reply) ? '1' : '0',
  sortDirection === 'asc' ? 'asc' : 'desc',
]);

export const preserveChatListPage = (incoming, current) => {
  const currentPage = current?.meta?.current_page;
  if (!currentPage) return incoming;

  return {
    ...incoming,
    meta: { ...incoming.meta, current_page: currentPage },
    data: Number(currentPage) > 1 && Array.isArray(current.data) ? current.data : incoming.data,
  };
};
