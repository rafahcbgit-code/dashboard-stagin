/** @type {import('tailwindcss').Config} */
const tailwindColors = require('tailwindcss/colors');

// Existing palette utilities keep their original colors outside the user app.
// Inside The Thread shell, the same utilities resolve to the approved brand scale.
const scopedAccent = (name) => Object.fromEntries(
  Object.entries(tailwindColors[name]).map(([shade, hex]) => {
    const rgb = hex.match(/[a-f\d]{2}/gi).map(part => parseInt(part, 16)).join(' ');
    return [shade, `rgb(var(--cb-accent-${shade}-rgb, ${rgb}) / <alpha-value>)`];
  }),
);

module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./modules/**/*.vue",
    "./modules/**/*.js",
    "./src/**/*.{html,js}",
  ],
  theme: {
    extend: {
      colors: {
        blue: scopedAccent('blue'),
        emerald: scopedAccent('emerald'),
        green: scopedAccent('green'),
        indigo: scopedAccent('indigo'),
        sky: scopedAccent('sky'),
        teal: scopedAccent('teal'),
        primary: 'rgb(var(--cb-primary-rgb, 16 185 129) / <alpha-value>)',
        'primary-dark': 'rgb(var(--cb-primary-dark-rgb, 6 78 59) / <alpha-value>)',
        secondary: 'rgb(var(--cb-secondary-rgb, 15 118 110) / <alpha-value>)',
        canvas: 'rgb(var(--cb-canvas-rgb, 244 246 249) / <alpha-value>)',
        ink: 'rgb(var(--cb-ink-rgb, 15 23 42) / <alpha-value>)',
        muted: 'rgb(var(--cb-muted-rgb, 113 128 150) / <alpha-value>)',
        line: 'rgb(var(--cb-line-rgb, 231 236 239) / <alpha-value>)',
      },
      fontFamily: {
        'Outfit': ['Outfit', 'Helvetica', 'Arial', 'sans-serif'],
      },
      lineHeight: {
        '15': '3rem',  // Custom line height class
        '20': '65px',     // Custom line height class
        // Add more custom line height classes as needed
      },
      borderRadius: {
        '4xl': '2rem',
      },
      boxShadow: {
        card: '0 1px 3px rgb(15 23 42 / 0.06), 0 8px 24px rgb(15 23 42 / 0.04)',
      },
    },  
  },
  plugins: [
    require("tailwindcss-rtl"),
  ],
  darkMode: "class"
}
