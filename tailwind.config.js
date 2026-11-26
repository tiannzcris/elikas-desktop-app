/** @type {import('tailwindcss').Config} */
// The E-LIKAS "Ligao Map Sheet" tokens, the same as the web staff pages'
// (elikas-backend: tailwind.app.config.cjs, written down in DESIGN.md) --
// change them there first, then here. Class names are kept (bg-brand,
// text-brand-700, gray-*) so every page follows the new values.
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        // Anything that names, labels or acts (DESIGN.md, Map Caps Rule).
        label: ['"Barlow Condensed"', '"Arial Narrow"', 'ui-sans-serif', 'sans-serif'],
        // Captions: italic, hydro blue.
        caption: ['"Source Serif 4"', 'Georgia', 'serif'],
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
      // Square corners everywhere; only full circles stay round (avatars,
      // dots, seals, the sidebar's logo disc, center symbols).
      borderRadius: { none: '0', sm: '0', DEFAULT: '0', md: '0', lg: '0', xl: '0', '2xl': '0', '3xl': '0', full: '9999px' },
      colors: {
        // The one action colour, sienna (DESIGN.md, One Action Rule).
        brand: {
          DEFAULT: '#9C4F27',
          dark: '#7E3E1D',
          light: '#F2E4DA',
          50: '#F7EEE8',
          100: '#F2E4DA',
          200: '#E2C3AE',
          600: '#9C4F27',
          700: '#7E3E1D',
          800: '#653117',
        },
        // Greys tinted towards the sheet's ink and paper (gray-500 is 4.7:1 on paper).
        gray: { 50: '#F3F5EF', 100: '#E8ECE2', 200: '#DCE3D3', 300: '#C2CCC0', 400: '#95A397', 500: '#5F6E64', 600: '#4C5B51', 700: '#38463E', 800: '#25322A', 900: '#17241D' },
        // The logo's own navy (public/images/elikas-logo-mark.png).
        navy: { DEFAULT: '#094776', dark: '#073A61' },
        // Form-field border: 3.30:1 on white, the WCAG 1.4.11 minimum for
        // a control's boundary.
        field: '#868E9C',
        paper: { DEFAULT: '#F3F5EF', 2: '#E8ECE2', 3: '#DCE3D3' },
        ink: { DEFAULT: '#17241D', 2: '#46564C', 3: '#6B7A70' },
        sienna: { DEFAULT: '#9C4F27', dark: '#7E3E1D', tint: '#F2E4DA' },
        hydro: { DEFAULT: '#22689F', tint: '#DCE9F3' },
        bulletin: { DEFAULT: '#0B2A5B', 2: '#13396F', text: '#C9D7EC' },
        signal: '#F6C400',
        // The staff sidebar (web, Nov 2026): a green-grey ink, not near-black.
        sidebar: { DEFAULT: '#2B3D33', text: '#E4EAE5', label: '#A9BAAE' },
        'error-ink': '#B42318',
        // State colours, shared with the web and mobile apps: each means
        // its state and nothing else (DESIGN.md, Fixed Meaning Rule).
        center: { active: '#15803D', 'nearly-full': '#D97706', full: '#DC2626', 'on-standby': '#2563EB', closed: '#4B5563' },
        barangay: { watch: '#FACC15', 'watch-text': '#422006', flooding: '#F97316', preemptive: '#DC2626', forced: '#7F1D1D' },
      },
    },
  },
  plugins: [],
}
