import { render } from 'preact'
import './styles/tokens.css'
import './styles/base.css'
import './styles/components.css'
import './app.css'
import { App } from './App'

const root = document.getElementById('app')!
render(<App csrf={root.dataset.csrf ?? ''} />, root)
