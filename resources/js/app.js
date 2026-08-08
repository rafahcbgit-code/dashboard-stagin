import { createApp, h, watchEffect } from 'vue';
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
  const response = await axios.get(`/translations/${locale}`);
  return response.data;
}

// Function to fetch available locales from Laravel backend
async function fetchAvailableLocales() {
  const response = await axios.get('/locales');
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
  setup({ el, App, props, plugin }) {
    // Fetch the current locale and available locales from the Laravel backend
    axios.get('/current-locale').then(async (response) => {
      const currentLocale = response.data.locale;
      const availableLocales = await fetchAvailableLocales();

      const i18n = createI18n({
        legacy: false,
        locale: currentLocale, // Default locale
        fallbackLocale: 'en', // Fallback locale
        messages: {}, // Initial empty messages
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

      // Load the default locale messages
      if (availableLocales.includes(currentLocale)) {
        loadLocaleMessages(currentLocale).then(messages => {
          i18n.global.setLocaleMessage(currentLocale, messages);
        });
      }

      // Watch for locale changes and dynamically load new locale messages
      watchEffect(async () => {
        const newLocale = i18n.global.locale.value;
        if (!i18n.global.availableLocales.includes(newLocale) && availableLocales.includes(newLocale)) {
          const messages = await loadLocaleMessages(newLocale);
          i18n.global.setLocaleMessage(newLocale, messages);
        }
      });
    });
  },
  progress: {
    delay: 250,
    color: '#198754',
    includeCSS: true,
    showSpinner: false,
  },
});
