import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import 'ckeditor5/ckeditor5.css'
import '../css/app.css'

createApp(App).use(createPinia()).use(router).mount('#app')
