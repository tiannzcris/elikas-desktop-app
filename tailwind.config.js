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
      // Type scale (16px root), the web's: xs 13, sm 14 (tables, labels),
      // base 15 (body), lg 18 (section titles), xl 20, 2xl 24 (page titles).
      fontSize: {
        xs: ['0.8125rem', { lineHeight: '1.25rem' }],
        sm: ['0.875rem', { lineHeight: '1.375rem' }],
        base: ['0.9375rem', { lineHeight: '1.5rem' }],
        lg: ['1.125rem', { lineHeight: '1.75rem' }],
        xl: ['1.25rem', { lineHeight: '1.75rem' }],
        '2xl': ['1.5rem', { lineHeight: '2rem' }],
      },
      maxWidth: { content: '75rem' },
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
