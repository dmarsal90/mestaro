import { createApp } from 'vue'
import DinerApp from './DinerApp.vue'
import './diner.css'

createApp(DinerApp, { qr: location.pathname.split('/')[2] ?? '' }).mount('#app')
