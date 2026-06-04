import React, { useState } from 'react';

interface MyComponentProps {
    name?: string;
}

export default function MyComponent({ name = 'Mitglied' }: MyComponentProps) {
    const [count, setCount] = useState(0);

    return (
        <div style={{
            padding: '1.5rem',
            border: '1px solid var(--primary-hover, #3b82f6)',
            borderRadius: '8px',
            background: 'var(--card-background-color, #1e293b)',
            boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)',
            maxWidth: '400px',
            margin: '1rem 0'
        }}>
            <h3 style={{ marginTop: 0, color: 'var(--primary, #60a5fa)' }}>
                Willkommen bei React, {name}!
            </h3>
            <p style={{ fontSize: '0.95rem' }}>
                Dies ist eine interaktive React-Komponente, die als <strong>Insellösung</strong> in dein Twig-Template eingebettet wurde.
            </p>
            <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', marginTop: '1rem' }}>
                <button 
                    onClick={() => setCount(count + 1)}
                    style={{
                        padding: '0.5rem 1rem',
                        cursor: 'pointer'
                    }}
                >
                    Klicks: {count}
                </button>
                <span style={{ fontSize: '0.9rem', color: 'var(--muted-color, #94a3b8)' }}>
                    Zustand bleibt reaktiv!
                </span>
            </div>
        </div>
    );
}
