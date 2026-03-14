import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import App from './App.vue'

const router = createRouter({
	// HTML5 history mode — clean URLs without #
	// PageController has matching routes for / and /events/:id
	history: createWebHistory(generateUrl('/apps/reel')),
	routes: [
		{ path: '/',           name: 'list',  component: { template: '<div/>' } },
		{ path: '/events/:id', name: 'event',    component: { template: '<div/>' } },
		{ path: '/settings',   name: 'settings', component: { template: '<div/>' } },
	],
})

createApp(App).use(router).mount('#reel')
