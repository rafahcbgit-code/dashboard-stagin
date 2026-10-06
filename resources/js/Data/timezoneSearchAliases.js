/**
 * Common country and city names that customers use when searching for IANA zones.
 * Keep the IANA identifier as the option value; aliases are search-only metadata.
 */
export const timezoneSearchAliases = {
    'Asia/Riyadh': {
        city: { en: 'Riyadh', ar: 'الرياض' },
        aliases: ['Saudi Arabia', 'Saudi', 'KSA', 'السعودية', 'السعوديه', 'المملكة العربية السعودية'],
    },
    'Asia/Bahrain': {
        city: { en: 'Manama', ar: 'المنامة' },
        aliases: ['Bahrain', 'البحرين'],
    },
    'Asia/Kuwait': {
        city: { en: 'Kuwait City', ar: 'مدينة الكويت' },
        aliases: ['Kuwait', 'الكويت'],
    },
    'Asia/Muscat': {
        city: { en: 'Muscat', ar: 'مسقط' },
        aliases: ['Oman', 'Sultanate of Oman', 'عمان', 'سلطنة عمان'],
    },
    'Asia/Qatar': {
        city: { en: 'Doha', ar: 'الدوحة' },
        aliases: ['Qatar', 'قطر'],
    },
    'Asia/Dubai': {
        city: { en: 'Dubai', ar: 'دبي' },
        aliases: ['United Arab Emirates', 'UAE', 'Emirates', 'الإمارات', 'الامارات', 'الإمارات العربية المتحدة', 'الامارات العربية المتحدة'],
    },
};

