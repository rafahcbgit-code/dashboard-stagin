import { reactive } from 'vue';

const state = reactive({
  search: null,
  labels: null,
  assigned_to: null,
  status: null,
});

export function useChatFilterStore() {
  return state;
}

export function setChatFilters({ search = null, labels = null, assigned_to = null, status = null } = {}) {
  state.search = search;
  state.labels = labels;
  state.assigned_to = assigned_to;
  state.status = status;
}

export function clearChatFilters() {
  state.search = null;
  state.labels = null;
  state.assigned_to = null;
}

