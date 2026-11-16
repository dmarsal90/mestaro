import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { createAppRouter } from './router'
import './styles/app.css'

const app = createApp(App).use(createPinia())
app.use(createAppRouter())
app.mount('#app')
