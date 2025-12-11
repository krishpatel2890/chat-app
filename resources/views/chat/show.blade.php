<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">Chat — {{ $user->name }}</h2>
    </x-slot>

    <div class="flex gap-6 p-6 h-[78vh]">
        <div class="w-1/4 overflow-auto bg-white p-4 rounded shadow">
            <h3 class="font-medium">Friends</h3>
            <ul id="friendsList" class="mt-3 space-y-2">
                @foreach($friends as $f)
                    <li><a href="{{ route('chat.show', $f->id) }}"
                            class="block p-2 rounded hover:bg-gray-100 {{ $f->id == $user->id ? 'bg-gray-100' : '' }}">
                            {{ $f->name }}</a></li>
                @endforeach
            </ul>
        </div>

        <div class="flex-1 flex flex-col bg-white rounded shadow overflow-hidden">
            <div class="flex items-center p-4 border-b">
                <div class="mr-3">
                    <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center text-gray-600">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                </div>
                <div>
                    <div class="font-medium">{{ $user->name }}</div>
                </div>
            </div>

            <div id="messages" class="flex-1 p-4 overflow-y-auto space-y-3 bg-white"></div>

            <div class="p-4 border-t">
                <form id="messageForm" class="flex items-end gap-3" onsubmit="return false;">
                    <div class="relative">
                        <button id="attachBtn" type="button"
                            class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100">+</button>
                        <input id="attachment" name="attachment" type="file" class="hidden" />
                    </div>

                    <div class="flex-1">
                        <textarea id="body" rows="1" placeholder="Type a message"
                            class="w-full resize-none border rounded px-3 py-2"></textarea>
                        <div id="previewFiles" class="mt-2 flex gap-2"></div>
                    </div>

                    <div>
                        <button id="sendBtn" class="px-4 py-2 bg-blue-600 text-white rounded">Send</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="w-1/4 bg-white p-4 rounded shadow overflow-auto">
            <h3 class="font-medium">Contact Info</h3>
            <div class="mt-3 text-sm text-gray-600">
                <div><strong>Name:</strong> {{ $user->name }}</div>
                <div><strong>Email:</strong> {{ $user->email }}</div>
                <hr class="my-3" />
                <h4 class="font-medium">Shared Media</h4>
                <div id="sharedMedia" class="mt-2 grid grid-cols-2 gap-2"></div>
            </div>
        </div>
    </div>

    <script>
        // ---------------- safe CSRF (single shared value) ----------------
        if (typeof window._csrfToken === 'undefined') {
            const meta = document.querySelector('meta[name="csrf-token"]');
            window._csrfToken = (meta && meta.content) ? meta.content : '';
        }
        const csrf = window._csrfToken;

        // ---------------- blade-passed variables ----------------
        const currentUserId = {{ auth()->id() }};
        const otherUserId = {{ $user->id }};
        // pass server-stored recipient public key safely (may be null)
        const userPublicKeyPEM = @json($user->public_key ?? null);

        // ----------------- WebCrypto helpers (robust) -----------------
        function arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
            return btoa(binary);
        }

        function base64ToArrayBuffer(base64) {
            // normalize url-safe base64 and pad
            let s = base64.replace(/\s+/g, '');
            s = s.replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4 !== 0) s += '=';
            const binary = atob(s);
            const len = binary.length;
            const bytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i);
            return bytes.buffer;
        }

        async function exportRsaPublicKeyToPem(key) {
            const spki = await crypto.subtle.exportKey('spki', key);
            const b64 = arrayBufferToBase64(spki);
            const chunks = b64.match(/.{1,64}/g) || [b64];
            return '-----BEGIN PUBLIC KEY-----\n' + chunks.join('\n') + '\n-----END PUBLIC KEY-----';
        }

        async function exportPrivateKeyToPkcs8(key) {
            const pkcs8 = await crypto.subtle.exportKey('pkcs8', key);
            return arrayBufferToBase64(pkcs8);
        }

        async function importPrivateKeyFromPkcs8(p8base64) {
            if (!p8base64) return null;
            const ab = base64ToArrayBuffer(p8base64);
            return await crypto.subtle.importKey(
                'pkcs8',
                ab,
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                true,
                ['decrypt']
            );
        }

        // PEM/base64 helpers
        // function pemToBase64(pem) {
        //     if (!pem || typeof pem !== 'string') return '';
        //     return pem
        //         .replace(/-----BEGIN PUBLIC KEY-----/g, '')
        //         .replace(/-----END PUBLIC KEY-----/g, '')
        //         .replace(/\r?\n|\r/g, '')
        //         .trim();
        // }

        // function looksLikePem(s) {
        //     return typeof s === 'string' && s.includes('-----BEGIN') && s.includes('PUBLIC KEY');
        // }

        // function quickIsBase64(s) {
        //     if (!s || typeof s !== 'string') return false;
        //     const t = s.replace(/\s+/g, '');
        //     return /^[A-Za-z0-9+\/\-_]+=*$/.test(t);
        // }

        // /**
        //  * Import an RSA public key from PEM or base64 (spki).
        //  * Returns a CryptoKey suitable for RSA-OAEP.
        //  */
        // async function importRsaPublicKeyFromPem(pemOrBase64) {
        //     if (!pemOrBase64 || typeof pemOrBase64 !== 'string') {
        //         throw new Error('No public key provided to importRsaPublicKeyFromPem');
        //     }

        //     // decide if PEM or raw base64
        //     let base64;
        //     if (looksLikePem(pemOrBase64)) {
        //         base64 = pemToBase64(pemOrBase64);
        //     } else {
        //         base64 = pemOrBase64.replace(/\r?\n|\r/g, '').trim();
        //     }

        //     // normalize URL-safe base64
        //     base64 = base64.replace(/-/g, '+').replace(/_/g, '/');
        //     while (base64.length % 4 !== 0) base64 += '=';

        //     if (!quickIsBase64(base64)) {
        //         console.error('importRsaPublicKeyFromPem: input not valid base64/PEM preview:', pemOrBase64.slice(0, 200));
        //         throw new Error('Public key is not valid PEM or base64.');
        //     }

        //     let binaryDer;
        //     try {
        //         binaryDer = atob(base64);
        //     } catch (err) {
        //         console.error('importRsaPublicKeyFromPem: atob failed', err);
        //         throw new Error('Failed to decode base64 public key.');
        //     }

        //     const binaryArray = Uint8Array.from(binaryDer, c => c.charCodeAt(0));

        //     // import key (use buffer)
        //     return await crypto.subtle.importKey(
        //         'spki',
        //         binaryArray.buffer,
        //         { name: 'RSA-OAEP', hash: 'SHA-256' },
        //         true,
        //         ['encrypt']
        //     );
        // }
        // ====== Replace existing pemToBase64 + importRsaPublicKeyFromPem with this ======

        function pemToBase64(pem) {
            if (!pem || typeof pem !== 'string') return '';
            // Remove PEM header/footer (if present)
            let s = pem.replace(/-----BEGIN PUBLIC KEY-----/g, '')
                .replace(/-----END PUBLIC KEY-----/g, '');

            // Remove BOTH actual newlines and literal "\n" sequences (escaped)
            s = s.replace(/\r?\n|\r/g, '');   // actual newlines
            s = s.replace(/\\r|\\n/g, '');   // literal backslash-r/ backslash-n sequences

            // Some blades might produce extra backslashes or HTML entities; strip stray backslashes
            s = s.replace(/\\/g, '');

            // Remove spaces/tabs that shouldn't be there
            s = s.replace(/\s+/g, '');

            return s.trim();
        }

        function quickIsBase64(s) {
            if (!s || typeof s !== 'string') return false;
            const t = s.replace(/\s+/g, '');
            // allow standard and URL-safe base64, with optional padding
            return /^[A-Za-z0-9+\/\-_]+=*$/.test(t);
        }

        async function importRsaPublicKeyFromPem(pemOrBase64) {
            if (!pemOrBase64 || typeof pemOrBase64 !== 'string') {
                throw new Error('No public key provided to importRsaPublicKeyFromPem');
            }

            // First try to clean possible escaping/extra chars aggressively
            let candidate = pemOrBase64;

            // If it looks like PEM, strip headers using helper
            if (typeof candidate === 'string' && candidate.includes('-----BEGIN')) {
                candidate = pemToBase64(candidate);
            } else {
                // Remove escaped newline sequences and stray backslashes, then actual newlines
                candidate = candidate.replace(/\\r|\\n/g, '').replace(/\\/g, '').replace(/\r?\n|\r/g, '').trim();
            }

            // Normalize URL-safe base64 (and pad)
            candidate = candidate.replace(/-/g, '+').replace(/_/g, '/');
            while (candidate.length % 4 !== 0) candidate += '=';

            // Quick validity check
            if (!quickIsBase64(candidate)) {
                console.error('importRsaPublicKeyFromPem: input not valid base64/PEM preview:', pemOrBase64.slice(0, 300));
                throw new Error('Public key is not valid PEM or base64.');
            }

            // Decode and import
            let binaryDer;
            try {
                binaryDer = atob(candidate);
            } catch (err) {
                console.error('importRsaPublicKeyFromPem: atob failed; preview:', candidate.slice(0, 200), err);
                throw new Error('Failed to decode base64 public key.');
            }

            const binaryArray = Uint8Array.from(binaryDer, c => c.charCodeAt(0));

            return await crypto.subtle.importKey(
                'spki',
                binaryArray.buffer,
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                true,
                ['encrypt']
            );
        }


        // AES helpers
        async function createAesKey() {
            const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
            const raw = await crypto.subtle.exportKey('raw', key);
            return { key, rawBase64: arrayBufferToBase64(raw) };
        }

        async function encryptWithAesGcm(aesKey, plaintext) {
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const enc = new TextEncoder();
            const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, enc.encode(plaintext));
            return { cipherBase64: arrayBufferToBase64(ct), ivBase64: arrayBufferToBase64(iv) };
        }

        async function encryptBlobWithAesGcm(aesKey, arrayBuffer) {
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, arrayBuffer);
            return { cipherBase64: arrayBufferToBase64(ct), ivBase64: arrayBufferToBase64(iv) };
        }

        async function wrapAesKeyWithRsa(aesRawBuffer, recipientPublicKeyCrypto) {
            const wrapped = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, recipientPublicKeyCrypto, aesRawBuffer);
            return arrayBufferToBase64(wrapped);
        }

        // local device key storage
        const DEVICE_PRIV_KEY_LS = 'e2ee_device_priv_pkcs8';
        const DEVICE_PUB_KEY_LS = 'e2ee_device_pub_spki';

        async function generateAndStoreDeviceKeypair() {
            const keyPair = await crypto.subtle.generateKey(
                { name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
                true,
                ['encrypt', 'decrypt']
            );
            const pubPem = await exportRsaPublicKeyToPem(keyPair.publicKey);
            const privPkcs8 = await exportPrivateKeyToPkcs8(keyPair.privateKey);

            localStorage.setItem(DEVICE_PRIV_KEY_LS, privPkcs8);
            localStorage.setItem(DEVICE_PUB_KEY_LS, pubPem);

            // upload public key if axios is present
            if (typeof axios !== 'undefined') {
                try {
                    await axios.post("{{ route('user.publickey') }}", {
                        public_key: pubPem,
                        public_key_alg: 'rsa-oaep-sha256'
                    }, { headers: { 'X-CSRF-TOKEN': csrf } });
                } catch (err) {
                    console.warn('upload public key failed', err);
                }
            } else {
                console.warn('axios not available; skipping public key upload');
            }

            return { pubPem, privPkcs8 };
        }

        async function ensureDeviceKey() {
            let pub = localStorage.getItem(DEVICE_PUB_KEY_LS);
            let priv = localStorage.getItem(DEVICE_PRIV_KEY_LS);
            if (!pub || !priv) {
                const pair = await generateAndStoreDeviceKeypair();
                pub = pair.pubPem; priv = pair.privPkcs8;
            }
            return { pubPem: pub, privPkcs8: priv };
        }

        async function importDevicePrivateKeyFromLocal() {
            const pkcs8 = localStorage.getItem(DEVICE_PRIV_KEY_LS);
            if (!pkcs8) return null;
            return await importPrivateKeyFromPkcs8(pkcs8);
        }

        // ----------------- Chat logic -----------------

        async function renderMessages(messages) {
            const container = document.getElementById('messages');
            container.innerHTML = '';
            messages.forEach(m => {
                const isMe = m.sender_id === currentUserId;
                const wrapper = document.createElement('div');
                wrapper.className = 'flex ' + (isMe ? 'justify-end' : 'justify-start');
                const bubble = document.createElement('div');
                bubble.className = (isMe ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900') + ' p-3 rounded-lg max-w-[60%]';
                if (m.body) {
                    const p = document.createElement('div');
                    p.innerText = '[encrypted message]';
                    bubble.appendChild(p);
                }
                if (m.attachment) {
                    const a = document.createElement('div'); a.className = 'mt-2';
                    const link = document.createElement('a');
                    link.href = `/storage/${m.attachment}`;
                    link.target = '_blank';
                    link.innerText = 'Encrypted file (download & decrypt client-side)';
                    link.className = 'underline';
                    a.appendChild(link);
                    bubble.appendChild(a);
                }
                const time = document.createElement('div');
                time.className = 'text-xs mt-2 text-gray-400 text-right';
                time.innerText = new Date(m.created_at).toLocaleString();
                bubble.appendChild(time);
                wrapper.appendChild(bubble);
                container.appendChild(wrapper);
            });
        }

        function renderSharedMedia(messages) {
            const container = document.getElementById('sharedMedia');
            container.innerHTML = '';
            messages.filter(m => m.attachment).forEach(m => {
                const div = document.createElement('div');
                div.className = 'p-2 border rounded text-xs';
                div.innerText = m.attachment.split('/').pop();
                container.appendChild(div);
            });
        }

        function scrollBottom() {
            const el = document.getElementById('messages');
            el.scrollTop = el.scrollHeight;
        }

        async function loadMessages() {
            if (typeof axios === 'undefined') {
                console.warn('axios not available — cannot load messages');
                return;
            }
            try {
                const res = await axios.get(`/chat/${otherUserId}/messages`);
                const messages = res.data || [];
                renderMessages(messages);
                renderSharedMedia(messages);
                scrollBottom();
            } catch (err) {
                console.error('loadMessages error', err);
            }
        }

        // attachment UI
        const attachBtn = document.getElementById('attachBtn');
        if (attachBtn) {
            attachBtn.addEventListener('click', () => {
                const attachmentInput = document.getElementById('attachment');
                if (attachmentInput) attachmentInput.click();
            });
        }

        const attachmentInput = document.getElementById('attachment');
        if (attachmentInput) {
            attachmentInput.addEventListener('change', (e) => {
                const preview = document.getElementById('previewFiles');
                preview.innerHTML = '';
                const file = e.target.files[0];
                if (!file) return;
                if (file.type && file.type.startsWith('image')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.className = 'w-20 h-20 object-cover rounded';
                    preview.appendChild(img);
                } else {
                    const name = document.createElement('div');
                    name.className = 'p-2 border rounded';
                    name.innerText = file.name;
                    preview.appendChild(name);
                }
            });
        }

        // send button — encrypt before uploading
        const sendBtn = document.getElementById('sendBtn');
        if (sendBtn) {
            sendBtn.addEventListener('click', async () => {
                const bodyEl = document.getElementById('body');
                const plain = bodyEl ? bodyEl.value.trim() : '';
                const file = (document.getElementById('attachment') || {}).files ? document.getElementById('attachment').files[0] : null;

                if (!plain && !file) return;

                try {
                    // ensure device keys exist
                    const deviceKeys = await ensureDeviceKey();
                    const devicePriv = await importDevicePrivateKeyFromLocal();
                    const devicePubPem = deviceKeys.pubPem;

                    // recipient public key (from blade); may be null
                    let recipientPubPem = userPublicKeyPEM;
                    if (!recipientPubPem) {
                        alert('Recipient does not have a public key registered yet.');
                        return;
                    }

                    // import recipient RSA key (robust)
                    let recipientPubKey;
                    try {
                        recipientPubKey = await importRsaPublicKeyFromPem(recipientPubPem);
                    } catch (err) {
                        console.error('recipient public key import failed', err);
                        alert('Recipient public key invalid or corrupt.');
                        return;
                    }

                    // create AES key and encrypt content
                    const { key: aesKey, rawBase64: aesRawBase64 } = await createAesKey();
                    let cipherTextBase64 = null;
                    let ivBase64ForText = null;

                    if (plain) {
                        const encRes = await encryptWithAesGcm(aesKey, plain);
                        cipherTextBase64 = encRes.cipherBase64;
                        ivBase64ForText = encRes.ivBase64;
                    }

                    let attachmentBlob = null;
                    let attachmentMime = null;
                    let fileIvBase64 = null;
                    if (file) {
                        const arrayBuffer = await file.arrayBuffer();
                        const encFile = await encryptBlobWithAesGcm(aesKey, arrayBuffer);
                        const cipherBuffer = base64ToArrayBuffer(encFile.cipherBase64);
                        attachmentBlob = new Blob([cipherBuffer], { type: 'application/octet-stream' });
                        attachmentMime = file.type || 'application/octet-stream';
                        fileIvBase64 = encFile.ivBase64;
                    }

                    // wrap AES raw for recipient & sender device
                    const aesRawBuffer = base64ToArrayBuffer(aesRawBase64);
                    const wrappedForRecipient = await wrapAesKeyWithRsa(aesRawBuffer, recipientPubKey);
                    const devicePubCrypto = await importRsaPublicKeyFromPem(devicePubPem);
                    const wrappedForSender = await wrapAesKeyWithRsa(aesRawBuffer, devicePubCrypto);

                    const encryptedKeys = {
                        devices: {
                            receiver_device: wrappedForRecipient,
                            sender_device: wrappedForSender
                        },
                        algorithm: 'rsa-oaep-sha256'
                    };

                    const meta = {};
                    if (file) {
                        meta.filename = file.name;
                        meta.file_iv = fileIvBase64;
                    }

                    const fd = new FormData();
                    fd.append('receiver_id', otherUserId);
                    if (cipherTextBase64) fd.append('body', cipherTextBase64);
                    if (ivBase64ForText) fd.append('iv', ivBase64ForText);
                    if (attachmentBlob) {
                        fd.append('attachment', attachmentBlob, file.name + '.enc');
                        fd.append('mime', attachmentMime);
                    }
                    fd.append('enc_algo', 'aes-256-gcm');
                    fd.append('encrypted_keys', JSON.stringify(encryptedKeys));
                    fd.append('meta', JSON.stringify(meta));

                    if (typeof axios === 'undefined') {
                        alert('Network error: axios not loaded.');
                        return;
                    }

                    await axios.post("{{ route('chat.send') }}", fd, {
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Content-Type': 'multipart/form-data'
                        }
                    });

                    // UI cleanup
                    if (bodyEl) bodyEl.value = '';
                    const attachmentField = document.getElementById('attachment');
                    if (attachmentField) attachmentField.value = '';
                    const preview = document.getElementById('previewFiles');
                    if (preview) preview.innerHTML = '';

                    // reload messages
                    await loadMessages();
                } catch (err) {
                    console.error('send error', err);
                    alert('Send failed — check console for details.');
                }
            });
        }

        // initial load and polling (guard axios)
        (async () => {
            try {
                await loadMessages();
                setInterval(loadMessages, 5000);
            } catch (err) {
                console.error(err);
            }
        })();

        // ensure device key exists on open
        ensureDeviceKey().catch(err => console.error('device key error', err));
    </script>
</x-app-layout>