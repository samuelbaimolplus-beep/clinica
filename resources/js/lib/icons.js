/** Iconos internos usados para orientar tareas y navegación; no representan permisos. */
const paths = {
    person:'M20 21v-2a7 7 0 0 0-14 0v2M13 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    calendar:'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M8 14h2M14 14h2M8 18h2',
    heart:'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8',
    clock:'M12 8v4l3 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
    file:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6M14 2v6h6M8 13h8M8 17h5',
    wallet:'M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v12H5a3 3 0 0 1-3-3V6M16 13h6v4h-6z',
    shield:'M12 22s9-4 9-11V5l-9-3-9 3v6c0 7 9 11 9 11M8 12l3 3 5-6',
    search:'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
    arrow:'M5 12h14M13 6l6 6-6 6',
    menu:'M4 6h16M4 12h16M4 18h16',
    close:'M6 6l12 12M18 6 6 18',
    logout:'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    check:'M5 12l4 4L19 6',
};
export const icon = (name, className = '') => `<svg class="ui-icon ${className}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name] || paths.file}"/></svg>`;
export const viewIcon = view => ({CU02:'shield',CU03:'users',CU04:'heart',CU05:'heart',CU06:'users',CU07:'clock',CU08:'search',CU09:'calendar',CU10:'calendar',CU11:'calendar',CU12:'calendar',CU13:'check',CU14:'heart',CU15:'file',CU16:'file',CU17:'calendar',CU18:'calendar',CU19:'wallet',CU20:'wallet',CU21:'heart',CU22:'shield',CU23:'users',CU24:'clock',CU25:'clock',CU26:'wallet'}[view] || 'file');
