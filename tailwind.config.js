/** @type {import('tailwindcss').Config} */
/**
 * Base de verdad — identidad FVD (sincronizada con :root en dist/assets/css/main.css).
 * Usar clases bg-fvd-primary, text-fvd-secondary, max-h-fvd-table, etc. en HTML/JS.
 * Valores oficiales del portal: azul profundo #2e2e8e, oro #ffd700.
 */
module.exports = {
  content: [
    './index.php',
    './panel.html',
    './*.html',
    './app/Modulos/**/*.{php,html}',
    './afiliar_atleta.html',
    './src/js/**/*.js',
    './design/**/*.html',
  ],
  theme: {
    extend: {
      colors: {
        fvd: {
          primary: '#2e2e8e',
          'primary-dark': '#1a1a5e',
          'primary-elevated': '#252570',
          secondary: '#ffd700',
          'secondary-hover': '#ffe44d',
          'secondary-active': '#e6c200',
          background: '#f4f7fb',
          surface: '#ffffff',
          'text-on-light': '#0f172a',
          danger: '#c41e3a',
          /* Pasteles +30% — títulos de tarjetas (.cursorrules) */
          'pastel-blue': '#cae6fc',
          'pastel-violet': '#e8ccec',
          'pastel-mint': '#d4ecd5',
          'pastel-amber': '#fff1c5',
          'pastel-rose': '#facada',
          'pastel-slate': '#dae0e4',
          'pastel-indigo': '#d2d6ee',
          /* Acciones corporativas */
          action: {
            confirm: '#16a34a',
            edit: '#2563eb',
            pending: '#ea580c',
            danger: '#dc2626',
            neutral: '#52525b',
          },
        },
      },
      backgroundImage: {
        'fvd-card-servicios': 'linear-gradient(180deg, #cae6fc 0%, #ffffff 72%)',
        'fvd-card-supervision': 'linear-gradient(180deg, #facada 0%, #ffffff 72%)',
        'fvd-card-operaciones': 'linear-gradient(180deg, #d2d6ee 0%, #ffffff 72%)',
        'fvd-card-finanzas': 'linear-gradient(180deg, #d4ecd5 0%, #ffffff 72%)',
        'fvd-card-delegado': 'linear-gradient(180deg, #e8ccec 0%, #ffffff 72%)',
      },
      spacing: {
        '14p': '14rem',
      },
      maxHeight: {
        'fvd-table': '70vh',
      },
      borderRadius: {
        fvd: '8px',
        'fvd-sm': '6px',
      },
    },
  },
  plugins: [],
};
