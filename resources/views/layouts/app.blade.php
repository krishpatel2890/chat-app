<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts / Styles (Vite) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>

<body class="font-sans antialiased">
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
        @include('layouts.navigation')

        <!-- Page Heading -->
        @isset($header)
            <header class="bg-white dark:bg-gray-800 shadow">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endisset

        <!-- Page Content -->
        <main>
            {{ $slot }}
        </main>
    </div>

    <!-- load axios late so it doesn't interfere with Vite bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

    <!-- ensure axios has CSRF header -->
    <script>
        (function () {
            const tokenEl = document.querySelector('meta[name="csrf-token"]');
            if (window.axios && tokenEl) {
                axios.defaults.headers.common['X-CSRF-TOKEN'] = tokenEl.content;
            }
        })();
    </script>

    @stack('scripts')

    <!-- E2EE device-key: only for authenticated users, run after DOM ready -->
    @auth
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                (async function () {
                    try {
                        // minimal helpers
                        function arrayBufferToBase64(buffer) {
                            const bytes = new Uint8Array(buffer);
                            let binary = '';
                            for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                            return btoa(binary);
                        }

                        async function exportPublicKeyToPem(publicKey) {
                            const spki = await crypto.subtle.exportKey('spki', publicKey);
                            const b64 = arrayBufferToBase64(spki);
                            const chunks = b64.match(/.{1,64}/g) || [];
                            return '-----BEGIN PUBLIC KEY-----\\n' + chunks.join('\\n') + '\\n-----END PUBLIC KEY-----';
                        }

                        async function exportPrivateKeyToPkcs8(privateKey) {
                            const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey);
                            return arrayBufferToBase64(pkcs8);
                        }

                        const PRIV_LS = 'e2ee_device_priv_pkcs8';
                        const PUB_LS = 'e2ee_device_pub_spki';

                        async function generateDeviceKeypair() {
                            const kp = await crypto.subtle.generateKey(
                                { name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
                                true,
                                ['encrypt', 'decrypt']
                            );
                            const pubPem = await exportPublicKeyToPem(kp.publicKey);
                            const privPkcs8 = await exportPrivateKeyToPkcs8(kp.privateKey);
                            localStorage.setItem(PRIV_LS, privPkcs8);
                            localStorage.setItem(PUB_LS, pubPem);
                            return { pubPem, privPkcs8 };
                        }

                        async function ensureDeviceKeyLocal() {
                            let pub = localStorage.getItem(PUB_LS);
                            let priv = localStorage.getItem(PRIV_LS);
                            if (!pub || !priv) {
                                return await generateDeviceKeypair();
                            }
                            return { pubPem: pub, privPkcs8: priv };
                        }

                        // ensure axios available
                        if (typeof axios === 'undefined') {
                            console.warn('axios missing — skipping public key upload');
                            return;
                        }

                        // fetch server-side public key (route: user.publickey.get)
                        let serverKey = null;
                        try {
                            const res = await axios.get("{{ route('user.publickey.get') }}");
                            serverKey = res.data && res.data.public_key ? res.data.public_key : null;
                        } catch (e) {
                            // If server returns an error (e.g., route missing or 401), bail quietly.
                            console.warn('Could not fetch server public key', e);
                            return;
                        }

                        // ensure local device key exists
                        const local = await ensureDeviceKeyLocal();
                        if (!local || !local.pubPem) return;

                        // upload only if server doesn't have a key (prevent overwriting)
                        if (!serverKey) {
                            try {
                                await axios.post("{{ route('user.publickey') }}", {
                                    public_key: local.pubPem,
                                    public_key_alg: 'rsa-oaep-sha256'
                                });
                                console.log('public key uploaded');
                            } catch (err) {
                                console.warn('upload public key failed', err);
                            }
                        } else {
                            // server already has a public key — do nothing
                            // console.log('server already has public key; not overwriting');
                        }

                    } catch (err) {
                        console.error('device-key flow error', err);
                    }
                })();
            });
        </script>
    @endauth
    <!-- <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script> -->
</body>

</html>