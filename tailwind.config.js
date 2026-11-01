/** @type {import('tailwindcss').Config} */
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
        primary: '#10B981',
        'primary-dark': '#064E3B',
        secondary: '#0F766E',
        canvas: '#F4F6F9',
        ink: '#0F172A',
        muted: '#718096',
        line: '#E7ECEF',
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
