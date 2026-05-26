/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./*.html', './js/**/*.js'],
  theme: {
    extend: {
      colors: {
        'shadow-grey': {
          DEFAULT: '#1e1e24',
          100: '#060607', 200: '#0c0c0e', 300: '#121216',
          400: '#18181d', 500: '#1e1e24', 600: '#474754',
          700: '#6f6f85', 800: '#9e9eae', 900: '#cfcfd7'
        },
        'oxblood': {
          DEFAULT: '#92140c',
          100: '#1d0402', 200: '#3a0805', 300: '#580c07',
          400: '#751109', 500: '#92140c', 600: '#d31e11',
          700: '#ef483c', 800: '#f4857d', 900: '#fac2be'
        },
        'floral-white': {
          DEFAULT: '#fff8f0',
          100: '#633500', 200: '#c66a00', 300: '#ff9c2a',
          400: '#ffca8d', 500: '#fff8f0', 600: '#fff9f3',
          700: '#fffbf6', 800: '#fffcf9', 900: '#fffefc'
        }
      },
      fontFamily: {
        display:  ['"Cinzel Decorative"', 'serif'],
        heading:  ['"Cinzel"', 'serif'],
        body:     ['"Crimson Text"', 'serif'],
      }
    }
  },
  plugins: []
}
