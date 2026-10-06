import { createApp, h, watch } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import VueTelInput from 'vue-tel-input';
import { createI18n } from 'vue-i18n';
import axios from 'axios';

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
