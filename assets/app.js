import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

import './react.js';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Global clipboard copy helper with fallback for insecure contexts
window.copyToClipboard = function(text, element) {
    if (element.dataset.original) return;
    
    // Save original innerHTML
    element.dataset.original = element.innerHTML;

    // Helper to restore element state on successful copy
    const onSuccess = () => {
        element.innerHTML = 'Kopiert! ✓';
        element.classList.add('is-copied');
        setTimeout(() => {
            element.innerHTML = element.dataset.original;
            element.classList.remove('is-copied');
            delete element.dataset.original;
        }, 1200);
    };

    // Try modern Clipboard API if available and secure
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text)
            .then(onSuccess)
            .catch(err => {
                console.warn('Modern clipboard API failed, trying fallback: ', err);
                fallbackCopy(text, onSuccess);
            });
    } else {
        fallbackCopy(text, onSuccess);
    }
};

function fallbackCopy(text, callback) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    // Prevent scrolling and position offscreen
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2em';
    textArea.style.height = '2em';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            callback();
        } else {
            console.error('Fallback copy command was unsuccessful');
        }
    } catch (err) {
        console.error('Fallback copy command failed: ', err);
    }
    
    document.body.removeChild(textArea);
}

// Persist <details> open/closed state in localStorage
document.addEventListener('toggle', function(event) {
    if (event.target.tagName === 'DETAILS' && event.target.id) {
        localStorage.setItem('details-open-' + event.target.id, event.target.open ? 'true' : 'false');
    }
}, true);

// Save scroll position on form submissions
document.addEventListener('submit', function() {
    sessionStorage.setItem('scroll-position', window.scrollY);
});

// Restore open state of <details> elements and window scroll position
function restoreDetailsAndScroll() {
    document.querySelectorAll('details[id]').forEach(function(details) {
        const saved = localStorage.getItem('details-open-' + details.id);
        if (saved === 'true') {
            details.open = true;
        } else if (saved === 'false') {
            details.open = false;
        }
    });

    const scrollPos = sessionStorage.getItem('scroll-position');
    if (scrollPos !== null) {
        window.scrollTo(0, parseInt(scrollPos, 10));
        sessionStorage.removeItem('scroll-position');
    }
}

// Thousands separator live formatter for numeric inputs
function formatThousandsInputElement(input) {
    const oldVal = input.value;
    if (!oldVal) return;
    const isNegative = oldVal.trim().startsWith('-');
    const digitsOnly = oldVal.replace(/\D/g, '');
    if (!digitsOnly) {
        input.value = isNegative ? '-' : '';
        return;
    }
    const formatted = (isNegative ? '-' : '') + digitsOnly.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    input.value = formatted;
}

document.addEventListener('input', function(e) {
    if (e.target && (e.target.classList.contains('format-thousands') || e.target.dataset.format === 'thousands')) {
        const input = e.target;
        const oldPos = input.selectionStart;
        const oldLen = input.value.length;

        formatThousandsInputElement(input);

        const newLen = input.value.length;
        if (oldPos !== null) {
            const newPos = Math.max(0, oldPos + (newLen - oldLen));
            input.setSelectionRange(newPos, newPos);
        }
    }
});

function initThousandsInputs() {
    document.querySelectorAll('.format-thousands, [data-format="thousands"]').forEach(function(input) {
        if (input.value) {
            formatThousandsInputElement(input);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    restoreDetailsAndScroll();
    initThousandsInputs();
});
document.addEventListener('turbo:load', function() {
    restoreDetailsAndScroll();
    initThousandsInputs();
});

