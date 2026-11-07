<template>
  <div class="cb-thread-shell">
    <header class="cb-thread-header" :class="{ 'cb-thread-header-mobile-hidden': !showMobileHeader }">
      <Link href="/dashboard" class="cb-thread-brand" :aria-label="localized('الرئيسية', 'Chatberry home')">
        <img :src="'/images/chatberry-mark.png'" alt="" />
        <span>Chatberry</span>
      </Link>

      <button type="button" class="cb-thread-command" @click="openPalette" :aria-label="localized('البحث في الصفحات', 'Search destinations')">
        <Search :size="20" aria-hidden="true" />
        <span class="cb-thread-command-label">{{ localized('ابحث عن صفحة أو أداة…', 'Search pages and tools…') }}</span>
        <span class="cb-thread-kbd" dir="ltr">⌘ K</span>
      </button>

      <div class="cb-thread-header-actions">
        <button type="button" class="cb-thread-icon-button cb-thread-command-mobile" @click="openPalette" :aria-label="localized('البحث في الصفحات', 'Search destinations')">
          <Search :size="20" aria-hidden="true" />
        </button>
        <Link href="/notifications" class="cb-thread-icon-button" :aria-label="$t('Notifications')">
          <Bell :size="20" aria-hidden="true" />
          <span v-if="unreadNotifications > 0" class="cb-thread-notification-dot" />
        </Link>
        <LangToggle :languages="languages" :current-language="currentLanguage" />
        <div class="cb-thread-profile-wrap">
          <button type="button" class="cb-thread-user" :aria-expanded="profileOpen" :aria-label="localized('قائمة الحساب', 'Account menu')" @click="profileOpen = !profileOpen">
            <img v-if="user?.avatar" class="cb-thread-avatar" :src="`/media/${user.avatar}`" alt="" />
            <span v-else class="cb-thread-avatar" aria-hidden="true">{{ user?.first_name?.charAt(0) || 'C' }}</span>
            <span class="cb-thread-user-name">{{ user?.first_name }} {{ user?.last_name }}</span>
            <ChevronDown :size="15" aria-hidden="true" />
          </button>
          <div v-if="profileOpen" class="cb-thread-profile-menu">
            <button type="button" @click="profileOpen = false; profileModalOpen = true"><User :size="17" />{{ $t('View profile') }}</button>
            <button type="button" @click="profileOpen = false; teamsModalOpen = true"><Building2 :size="17" />{{ $t('Switch teams') }}</button>
            <Link href="/logout" @click="profileOpen = false"><LogOut :size="17" />{{ localized('تسجيل الخروج', 'Log out') }}</Link>
          </div>
        </div>
      </div>
    </header>

    <div class="cb-thread-body">
      <nav class="cb-thread-rail" :aria-label="localized('التنقل الرئيسي', 'Primary navigation')">
        <template v-for="group in groups" :key="group.id">
          <Link v-if="group.links.length === 1" :href="group.links[0].href" class="cb-thread-rail-item" :aria-current="groupActive(group) ? 'page' : undefined">
            <span class="cb-thread-rail-pill">
              <component :is="group.icon" :size="20" :stroke-width="1.75" aria-hidden="true" />
              <span v-if="group.id === 'chats' && unreadMessages > 0" class="cb-thread-badge">{{ unreadMessages }}</span>
            </span>
            <span>{{ group.label }}</span>
          </Link>
          <button v-else type="button" class="cb-thread-rail-item" :aria-current="groupActive(group) ? 'page' : undefined" :aria-expanded="openGroupId === group.id" @click="toggleGroup(group, $event)">
            <span class="cb-thread-rail-pill"><component :is="group.icon" :size="20" :stroke-width="1.75" aria-hidden="true" /></span>
            <span>{{ group.label }}</span>
          </button>
        </template>
        <div class="cb-thread-rail-spacer" />
        <button type="button" class="cb-thread-rail-item" :aria-expanded="openGroupId === 'more'" @click="toggleGroup(moreGroup, $event)">
          <span class="cb-thread-rail-pill"><MoreHorizontal :size="20" :stroke-width="1.75" aria-hidden="true" /></span>
          <span>{{ localized('المزيد', 'More') }}</span>
        </button>
      </nav>

      <main class="cb-thread-main" :class="{ 'cb-thread-main-chat': chatPage }"><slot /></main>
    </div>

    <nav class="cb-thread-bottom" :aria-label="localized('التنقل على الجوال', 'Mobile navigation')">
      <Link v-for="group in mobileGroups" :key="group.id" :href="group.links[0].href" class="cb-thread-rail-item" :aria-current="groupActive(group) ? 'page' : undefined">
        <span class="cb-thread-rail-pill">
          <component :is="group.icon" :size="20" :stroke-width="1.75" aria-hidden="true" />
          <span v-if="group.id === 'chats' && unreadMessages > 0" class="cb-thread-badge">{{ unreadMessages }}</span>
        </span>
        <span>{{ group.mobileLabel || group.label }}</span>
      </Link>
      <button type="button" class="cb-thread-rail-item" :aria-expanded="openGroupId === 'mobile-more'" @click="toggleGroup(mobileMoreGroup, $event)">
        <span class="cb-thread-rail-pill"><MoreHorizontal :size="20" :stroke-width="1.75" aria-hidden="true" /></span>
        <span>{{ localized('المزيد', 'More') }}</span>
      </button>
    </nav>

    <div v-if="activeFlyout" ref="flyout" class="cb-thread-flyout" :style="flyoutStyle">
      <h3>{{ activeFlyout.label }}</h3>
      <p>{{ localized('انتقل إلى إحدى الصفحات', 'Choose a destination') }}</p>
      <Link v-for="item in activeFlyout.links" :key="item.href" :href="item.href" :aria-current="linkActive(item) ? 'page' : undefined" @click="openGroupId = null">
        <span>{{ item.label }}</span><span v-if="item.id === 'notifications' && unreadNotifications > 0" class="cb-thread-badge-inline">{{ unreadNotifications }}</span>
      </Link>
    </div>

    <Dialog :open="paletteOpen" class="cb-thread-dialog" @close="closePalette">
      <div class="cb-thread-scrim" aria-hidden="true" />
      <div class="cb-thread-dialog-layer">
        <DialogPanel class="cb-thread-palette">
          <DialogTitle class="sr-only">{{ localized('البحث في الصفحات', 'Search destinations') }}</DialogTitle>
          <div class="cb-thread-palette-input">
            <Search :size="20" aria-hidden="true" />
            <input ref="paletteInput" v-model="query" :placeholder="localized('ابحث عن صفحة أو أداة…', 'Search pages and tools…')" @keydown="onPaletteKeydown" />
            <span class="cb-thread-kbd">Esc</span>
          </div>
          <div class="cb-thread-palette-list" role="listbox" :aria-label="localized('النتائج', 'Results')">
            <button v-for="(item, index) in paletteResults" :key="item.href" type="button" class="cb-thread-palette-option" role="option" :aria-selected="selectedIndex === index" @mouseenter="selectedIndex = index" @click="visit(item.href)">
              <component :is="item.icon" :size="18" aria-hidden="true" /><span>{{ item.label }}</span>
            </button>
            <p v-if="!paletteResults.length" class="cb-thread-palette-empty">{{ localized('لا توجد صفحات مطابقة', 'No matching destinations') }}</p>
          </div>
          <div class="cb-thread-palette-help">↑ ↓ {{ localized('للتنقل · Enter للفتح · Esc للإغلاق', 'to move · Enter to open · Esc to close') }}</div>
        </DialogPanel>
      </div>
    </Dialog>

    <Modal :label="$t('Switch teams')" :is-open="teamsModalOpen" :close-btn="true" @close="teamsModalOpen = false">
      <div class="cb-thread-team-list">
        <button v-for="item in organizations || []" :key="item.organization?.uuid" type="button" @click="selectOrganization(item.organization?.uuid)">
          <Building2 :size="18" aria-hidden="true" /><span>{{ item.organization?.name }}</span>
        </button>
        <button type="button" @click="teamsModalOpen = false; createOrganizationOpen = true"><Plus :size="18" aria-hidden="true" />{{ localized('إنشاء مؤسسة', 'Create organization') }}</button>
      </div>
    </Modal>
    <ProfileModal :user="user" :organization="organization" :is-open="profileModalOpen" role="user" @close="profileModalOpen = false" />
    <OrganizationModal v-model:model-value="createOrganizationOpen" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Home, MessageCircle, Users, Megaphone, Bot, BarChart3, Bell, Search, MoreHorizontal, ChevronDown, User, Building2, LogOut, FileText, Settings, CreditCard, CircleHelp, Code, Plus, MessageSquare, Workflow } from 'lucide-vue-next';
import LangToggle from '@/Components/LangToggle.vue';
import Modal from '@/Components/Modal.vue';
import ProfileModal from '@/Components/ProfileModal.vue';
import OrganizationModal from '@/Components/OrganizationModal.vue';

const props = defineProps({
  user: Object,
  organization: Object,
  organizations: [Array, Object],
  unreadMessages: { type: Number, default: 0 },
  unreadNotifications: { type: Number, default: 0 },
  showMobileHeader: { type: Boolean, default: true },
  chatPage: { type: Boolean, default: false },
});

const page = usePage();
const { t } = useI18n();
const currentLanguage = computed(() => page.props.currentLanguage || 'ar');
const languages = computed(() => page.props.languages || []);
const localized = (ar, en) => currentLanguage.value === 'ar' ? ar : en;
const isAgent = computed(() => {
  const p = page.props;
  return Boolean((p.value && (p.value.isAgent ?? p.value.is_agent)) ?? (props.user?.is_agent ?? props.user?.teams?.[0]?.role === 'agent'));
});
const isOwner = computed(() => props.user?.teams?.[0]?.role === 'owner');
const metaFlowsModule = computed(() => page.props.meta_flows_module ?? false);

const entry = (id, label, href, icon) => ({ id, label, href, icon });
const groups = computed(() => {
  const base = [
    { id: 'home', label: localized('الرئيسية', 'Home'), icon: Home, links: [entry('dashboard', t('Dashboard'), '/dashboard', Home)] },
    { id: 'chats', label: localized('المحادثات', 'Conversations'), icon: MessageCircle, links: [entry('chats', t('Chats'), '/chats', MessageCircle)] },
    { id: 'audience', label: localized('الجمهور', 'Audience'), mobileLabel: localized('العملاء', 'Contacts'), icon: Users, links: [entry('contacts', t('Contacts'), '/contacts', Users)] },
  ];
  const engage = [];
  if (!isAgent.value) engage.push(entry('campaigns', t('Campaigns'), '/campaigns', Megaphone));
  engage.push(entry('templates', t('Message templates'), '/templates', FileText));
  engage.push(entry('replies', t('Saved Replies'), '/pre-created-replies', MessageSquare));
  base.push({ id: 'engage', label: localized('الحملات', 'Engage'), icon: Megaphone, links: engage });
  if (!isAgent.value) {
    const automation = [entry('automation', t('Automation'), '/automation/triggers', Bot)];
    if (metaFlowsModule.value) automation.push(entry('flows', t('Meta Flows'), '/automation/meta-flows', Workflow));
    base.push({ id: 'automation', label: localized('الأتمتة', 'Automation'), icon: Bot, links: automation });
    base.push({ id: 'insights', label: localized('الرؤى', 'Insights'), icon: BarChart3, links: [
      entry('agent-reports', t('Agent Reports'), '/reports/agent', BarChart3),
      entry('general-reports', t('General Reports'), '/reports/general', BarChart3),
    ] });
  }
  return base;
});

const moreGroup = computed(() => {
  const links = [
    entry('notifications', t('Notifications'), '/notifications', Bell),
    entry('team', t('Team'), '/team', Users),
  ];
  if (!isAgent.value) {
    links.push(entry('settings', t('Settings'), '/settings', Settings));
    links.push(entry('billing', t('Billing'), '/billing', CreditCard));
  }
  links.push(entry('support', t('Support'), '/support', CircleHelp));
  if (isOwner.value) links.push(entry('developer', t('Developer'), '/developer-tools/access-tokens', Code));
  return { id: 'more', label: localized('المزيد', 'More'), links };
});
const mobileGroups = computed(() => groups.value.slice(0, 3));
const mobileMoreGroup = computed(() => ({ id: 'mobile-more', label: localized('المزيد', 'More'), links: [
  ...groups.value.slice(3).flatMap(group => group.links),
  ...moreGroup.value.links.map(item => item.id === 'settings' ? { ...item, href: '/settings/m' } : item),
] }));
const allDestinations = computed(() => [...groups.value.flatMap(group => group.links), ...moreGroup.value.links]);
const currentUrl = computed(() => page.url || '');
const linkActive = item => currentUrl.value === item.href || currentUrl.value.startsWith(`${item.href}/`);
const groupActive = group => {
  if (group.id === 'automation') return currentUrl.value.startsWith('/automation');
  if (group.id === 'more' || group.id === 'mobile-more') {
    return currentUrl.value.startsWith('/settings') || currentUrl.value.startsWith('/web-widget') || group.links.some(linkActive);
  }
  return group.links.some(linkActive);
};

const openGroupId = ref(null);
const flyoutStyle = ref({});
const activeFlyout = computed(() => openGroupId.value === 'mobile-more' ? mobileMoreGroup.value : openGroupId.value === 'more' ? moreGroup.value : groups.value.find(group => group.id === openGroupId.value));
const toggleGroup = (group, event) => {
  if (openGroupId.value === group.id) { openGroupId.value = null; return; }
  const rect = event.currentTarget.getBoundingClientRect();
  const rtl = document.documentElement.dir === 'rtl';
  flyoutStyle.value = { top: `${Math.max(75, Math.min(rect.top, window.innerHeight - 360))}px`, left: `${rtl ? Math.max(12, rect.left - 260) : Math.min(window.innerWidth - 262, rect.right + 8)}px` };
  openGroupId.value = group.id;
};

const paletteOpen = ref(false);
const paletteInput = ref(null);
const query = ref('');
const selectedIndex = ref(0);
const paletteResults = computed(() => allDestinations.value.filter((item, index, list) => list.findIndex(other => other.href === item.href) === index && item.label.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
watch(query, () => { selectedIndex.value = 0; });
const openPalette = async () => { openGroupId.value = null; query.value = ''; selectedIndex.value = 0; paletteOpen.value = true; await nextTick(); paletteInput.value?.focus(); };
const closePalette = () => { paletteOpen.value = false; };
const visit = href => { closePalette(); router.visit(href); };
const onPaletteKeydown = event => {
  if (event.key === 'ArrowDown') { event.preventDefault(); selectedIndex.value = Math.min(selectedIndex.value + 1, paletteResults.value.length - 1); }
  if (event.key === 'ArrowUp') { event.preventDefault(); selectedIndex.value = Math.max(selectedIndex.value - 1, 0); }
  if (event.key === 'Enter' && paletteResults.value[selectedIndex.value]) { event.preventDefault(); visit(paletteResults.value[selectedIndex.value].href); }
};
const handleKeydown = event => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openPalette(); }
  if (event.key === 'Escape') openGroupId.value = null;
};
const handleOutside = event => {
  if (!event.target.closest('.cb-thread-flyout, .cb-thread-rail-item, .cb-thread-profile-wrap')) { openGroupId.value = null; profileOpen.value = false; }
};
onMounted(() => { document.addEventListener('keydown', handleKeydown); document.addEventListener('click', handleOutside); });
onUnmounted(() => { document.removeEventListener('keydown', handleKeydown); document.removeEventListener('click', handleOutside); });
watch(currentUrl, () => { openGroupId.value = null; profileOpen.value = false; closePalette(); });

const profileOpen = ref(false);
const profileModalOpen = ref(false);
const teamsModalOpen = ref(false);
const createOrganizationOpen = ref(false);
const organizationForm = useForm({ uuid: null });
const selectOrganization = uuid => {
  if (!uuid) return;
  organizationForm.uuid = uuid;
  organizationForm.post('/select-organization', { preserveScroll: true, onFinish: () => { teamsModalOpen.value = false; } });
};
</script>
