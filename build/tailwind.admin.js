module.exports = {
  content: ['D:/clude_project/website/admin/**/*.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'Hind Siliguri', 'system-ui', 'sans-serif'],
        head: ['Plus Jakarta Sans', 'Anek Bangla', 'Hind Siliguri', 'sans-serif'],
      },
      colors: {
        indigo: {
          50:  'rgb(var(--c-primary) / 0.08)',
          100: 'rgb(var(--c-primary) / 0.14)',
          200: 'rgb(var(--c-primary) / 0.24)',
          500: 'rgb(var(--c-primary) / <alpha-value>)',
          600: 'rgb(var(--c-primary) / <alpha-value>)',
          700: 'rgb(var(--c-primary-2) / <alpha-value>)',
          800: 'rgb(var(--c-primary-2) / <alpha-value>)',
          900: 'rgb(var(--c-primary-2) / <alpha-value>)',
        },
        gray: {
          50:  'rgb(var(--c-surface-2) / <alpha-value>)',
          100: 'rgb(var(--c-bg) / <alpha-value>)',
          200: 'rgb(var(--c-border) / <alpha-value>)',
          300: 'rgb(var(--c-border) / <alpha-value>)',
          400: 'rgb(var(--c-text-muted) / <alpha-value>)',
          500: 'rgb(var(--c-text-muted) / <alpha-value>)',
          600: 'rgb(var(--c-text-muted) / <alpha-value>)',
          700: 'rgb(var(--c-text) / <alpha-value>)',
          800: 'rgb(var(--c-text) / <alpha-value>)',
          900: 'rgb(var(--c-text) / <alpha-value>)',
        },
      },
    },
  },
  plugins: [],
};
