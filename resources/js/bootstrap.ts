import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        initEcho: () => Echo<any>;
    }
}

if (typeof window !== 'undefined') {
    window.Pusher = Pusher;
}

export function initEcho(): Echo<any> {
    const broadcaster = import.meta.env.VITE_BROADCAST_DRIVER || 'pusher';

    if (broadcaster === 'reverb') {
        return new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    Authorization: `Bearer ${import.meta.env.VITE_REVERB_AUTH_TOKEN || ''}`,
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content || '',
                },
            },
            cluster: import.meta.env.VITE_REVERB_APP_CLUSTER || '',
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            host: import.meta.env.VITE_REVERB_HOST,
            port: import.meta.env.VITE_REVERB_PORT || 443,
            scheme: import.meta.env.VITE_REVERB_SCHEME || 'https',
        });
    }

    return new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
        forceTLS: true,
    });
}
