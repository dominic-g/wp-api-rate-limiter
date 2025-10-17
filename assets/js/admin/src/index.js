import { render } from '@wordpress/element';
import React from 'react';
import App from './App';
import './css/style.css';

// Ensure the DOM element exists before rendering
const container = document.getElementById( 'rlm-admin-app' );
if ( container ) {
    render( <App />, container );
} else {
    console.error( 'WP API Rate Limiter: Mount point #rlm-admin-app not found.' );
}