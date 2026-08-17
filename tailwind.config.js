/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#1E3D73',
          dark: '#0F2447',
          light: '#5B8FD6',
          red: '#C8102E',
          green: '#178A43',
        },
      },
    },
  },
  plugins: [],
}
