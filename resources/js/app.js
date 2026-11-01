import { createApp, h, watch } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import VueTelInput from 'vue-tel-input';
import { createI18n } from 'vue-i18n';
import axios from 'axios';

let revealObserver;

const motionIsReduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const animatePageSections = () => {
  if (typeof window === 'undefined' || motionIsReduced()) return;

  const pageKey = `chatberry:revealed:${window.location.pathname}`;
  if (window.sessionStorage.getItem(pageKey)) return;

  const candidates = [...document.querySelectorAll(
    '.cb-page-shell > .cb-page-inner, .cb-page-shell > section, .cb-page-shell > div, .cb-page-shell .cb-card, .cb-page-shell .cb-section, .cb-page-shell .cb-filter-bar',
  )].filter((element) => (
    !element.matches('[data-no-reveal]')
    && !element.closest('[data-no-reveal]')
    && !element.closest('.chat-bg, [data-chat-scroll-container]')
  ));

  const targets = candidates.filter((element, index) => !candidates.some((other, otherIndex) => (
    otherIndex !== index && other.contains(element) && other.matches('.cb-card, .cb-section, .cb-filter-bar')
  ))).slice(0, 18);

  if (!targets.length) return;
  window.sessionStorage.setItem(pageKey, '1');
  revealObserver?.disconnect();
  revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('cb-reveal-visible');
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -4% 0px' });

  targets.forEach((element, index) => {
    element.classList.add('cb-reveal');
    element.style.setProperty('--cb-reveal-delay', `${Math.min(index, 5) * 50}ms`);
    revealObserver.observe(element);
  });
};

const cleanupNavigationBlockers = () => {
  document.querySelectorAll('[inert]').forEach((element) => {
    element.removeAttribute('inert');
  });

  document.body.style.removeProperty('overflow');
  document.body.style.removeProperty('padding-right');
  document.body.style.removeProperty('pointer-events');
  document.documentElement.style.removeProperty('overflow');
  document.documentElement.style.removeProperty('padding-right');
  document.documentElement.style.removeProperty('pointer-events');
};

router.on('start', () => {
  cleanupNavigationBlockers();
  console.debug('Inertia navigation started');
});
router.on('finish', () => {
  console.debug('Inertia navigation finished');
  window.requestAnimationFrame(animatePageSections);
});
router.on('error', (error) => {
  console.error('Inertia request error', error);
});
router.on('invalid', (event) => {
  console.warn('Invalid Inertia response received', event.detail ?? event);
});

// Function to load locale messages via API
async function loadLocaleMessages(locale) {
  const response = await axios.get(`/translations/${encodeURIComponent(locale)}`);
  return response.data;
}

createInertiaApp({
  resolve: async (name) => {
    // Define paths to the components
    const pages = import.meta.glob('./Pages/**/*.vue');
    const modulePages = import.meta.glob('../../modules/**/Pages/**/*.vue');
    
    // Check if the name refers to a module component
    const [moduleName, pageName] = name.split('::');
    
    if (pageName) {
      const key = `../../modules/${moduleName}/Pages/${pageName}.vue`;
      const component = modulePages[key];
      
      if (component) {
        const resolvedComponent = await component();
        return resolvedComponent.default || resolvedComponent;
      }
    }
    
    // Otherwise, resolve from the standard Pages directory
    const component = pages[`./Pages/${name}.vue`];
    if (component) {
      const resolvedComponent = await component();
      return resolvedComponent.default || resolvedComponent;
    }
    
    throw new Error(`Page not found: ${name}`);
  },
  async setup({ el, App, props, plugin }) {
    const pageProps = props.initialPage?.props ?? {};
    const currentLocale = String(
      pageProps.currentLanguage || document.documentElement.lang || 'ar',
    ).toLowerCase();

    // Load translations before Vue mounts so the first visible render is already
    // in the selected language instead of flashing untranslated English keys.
    const [currentMessagesResult, fallbackMessagesResult] = await Promise.allSettled([
      loadLocaleMessages(currentLocale),
      currentLocale === 'en' ? Promise.resolve(null) : loadLocaleMessages('en'),
    ]);
    const currentMessages = currentMessagesResult.status === 'fulfilled'
      ? currentMessagesResult.value
      : {};
    const fallbackMessages = currentLocale === 'en'
      ? currentMessages
      : fallbackMessagesResult.status === 'fulfilled'
        ? fallbackMessagesResult.value
        : {};

    if (currentMessagesResult.status === 'rejected') {
      console.error(`Unable to load translations for locale "${currentLocale}"`, currentMessagesResult.reason);
    }

    document.documentElement.lang = currentLocale;
    document.documentElement.dir = currentLocale === 'ar' ? 'rtl' : 'ltr';

    const i18n = createI18n({
      legacy: false,
      locale: currentLocale,
      fallbackLocale: 'en',
      messages: {
        [currentLocale]: currentMessages,
        ...(currentLocale === 'en' ? {} : { en: fallbackMessages }),
      },
    });

    const app = createApp({ render: () => h(App, props) });

    app.config.errorHandler = (error, instance, info) => {
      console.error('Vue runtime error', error, info, instance);
    };

    app.use(plugin)
      .use(VueApexCharts)
      .use(VueTelInput)
      .use(i18n)
      .mount(el);

    window.requestAnimationFrame(animatePageSections);

    // Keep dynamically selected locales ready before rendering their strings.
    watch(i18n.global.locale, async (newLocale) => {
      if (i18n.global.availableLocales.includes(newLocale)) {
        return;
      }

      try {
        const messages = await loadLocaleMessages(newLocale);
        i18n.global.setLocaleMessage(newLocale, messages);
      } catch (error) {
        console.error(`Unable to load translations for locale "${newLocale}"`, error);
      }
    });
  },
  progress: {
    delay: 250,
    color: '#198754',
    includeCSS: true,
    showSpinner: false,
  },
});
