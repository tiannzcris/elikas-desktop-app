/** @type {import('tailwindcss').Config} */
// The same design tokens as the E-LIKAS web dashboard (its
// resources/views/partials/design-system.blade.php and
// docs/design-system.md) -- change them there first, then here.
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Public Sans"', 'ui-sans-serif', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', 'Arial', 'sans-serif'],
      },
      colors: {
        brand: {
          DEFAULT: '#2563EB',
          dark: '#1D4ED8',
          light: '#DBEAFE',
          50: '#EFF6FF',
          100: '#DBEAFE',
          200: '#BFDBFE',
          600: '#2563EB',
          700: '#1D4ED8',
          800: '#1E40AF',
        },
        // The logo's own navy (public/images/elikas-logo-mark.png).
        navy: { DEFAULT: '#094776', dark: '#073A61' },
        // Form-field border: 3.30:1 on white, the WCAG 1.4.11 minimum for
        // a control's boundary.
        field: '#868E9C',
      },
    },
  },
  plugins: [],
}
