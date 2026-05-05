

export const index = 0;
let component_cache;
export const component = async () => component_cache ??= (await import('../entries/pages/_layout.svelte.js')).default;
export const universal = {
  "ssr": false,
  "prerender": false
};
export const universal_id = "src/routes/+layout.ts";
export const imports = ["_app/immutable/nodes/0.C4W9TpX5.js","_app/immutable/chunks/BxkistFy.js","_app/immutable/chunks/QwiPRgO0.js","_app/immutable/chunks/BMufmft-.js","_app/immutable/chunks/NX7HY44j.js"];
export const stylesheets = ["_app/immutable/assets/0.DQ3MtTAG.css"];
export const fonts = [];
