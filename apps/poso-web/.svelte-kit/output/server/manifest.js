export const manifest = (() => {
function __memo(fn) {
	let value;
	return () => value ??= (value = fn());
}

return {
	appDir: "_app",
	appPath: "_app",
	assets: new Set([]),
	mimeTypes: {},
	_: {
		client: {start:"_app/immutable/entry/start.CyaJULny.js",app:"_app/immutable/entry/app.BOtbIgOY.js",imports:["_app/immutable/entry/start.CyaJULny.js","_app/immutable/chunks/DqwLkDvu.js","_app/immutable/chunks/QwiPRgO0.js","_app/immutable/chunks/DEEbb31x.js","_app/immutable/chunks/CDb4-qLf.js","_app/immutable/entry/app.BOtbIgOY.js","_app/immutable/chunks/QwiPRgO0.js","_app/immutable/chunks/HcwPRDA3.js","_app/immutable/chunks/BxkistFy.js","_app/immutable/chunks/CDb4-qLf.js","_app/immutable/chunks/C-xJYW2U.js","_app/immutable/chunks/NX7HY44j.js","_app/immutable/chunks/CA_y1ZVN.js","_app/immutable/chunks/DEEbb31x.js"],stylesheets:[],fonts:[],uses_env_dynamic_public:false},
		nodes: [
			__memo(() => import('./nodes/0.js')),
			__memo(() => import('./nodes/1.js')),
			__memo(() => import('./nodes/2.js')),
			__memo(() => import('./nodes/3.js')),
			__memo(() => import('./nodes/4.js')),
			__memo(() => import('./nodes/5.js')),
			__memo(() => import('./nodes/6.js')),
			__memo(() => import('./nodes/7.js')),
			__memo(() => import('./nodes/8.js'))
		],
		remotes: {
			
		},
		routes: [
			{
				id: "/",
				pattern: /^\/$/,
				params: [],
				page: { layouts: [0,], errors: [1,], leaf: 3 },
				endpoint: null
			},
			{
				id: "/(app)/purchases",
				pattern: /^\/purchases\/?$/,
				params: [],
				page: { layouts: [0,2,], errors: [1,,], leaf: 5 },
				endpoint: null
			},
			{
				id: "/(app)/purchases/new",
				pattern: /^\/purchases\/new\/?$/,
				params: [],
				page: { layouts: [0,2,], errors: [1,,], leaf: 6 },
				endpoint: null
			},
			{
				id: "/(app)/sales",
				pattern: /^\/sales\/?$/,
				params: [],
				page: { layouts: [0,2,], errors: [1,,], leaf: 7 },
				endpoint: null
			},
			{
				id: "/(app)/sales/new",
				pattern: /^\/sales\/new\/?$/,
				params: [],
				page: { layouts: [0,2,], errors: [1,,], leaf: 8 },
				endpoint: null
			},
			{
				id: "/(app)/[...path]",
				pattern: /^(?:\/([^]*))?\/?$/,
				params: [{"name":"path","optional":false,"rest":true,"chained":true}],
				page: { layouts: [0,2,], errors: [1,,], leaf: 4 },
				endpoint: null
			}
		],
		prerendered_routes: new Set([]),
		matchers: async () => {
			
			return {  };
		},
		server_assets: {}
	}
}
})();
