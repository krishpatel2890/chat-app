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

            <!-- E2EE status banner (shows if private key present) -->
            <div id="e2eeStatus" class="p-2 text-sm text-center bg-yellow-50 text-yellow-700 hidden"></div>

            <!-- messages container -->
            <div id="messages" class="flex-1 p-4 overflow-y-auto space-y-3 bg-white"></div>

            <div class="p-4 border-t">
                <!-- NOTE: changed form layout to ensure send button always visible -->
                <form id="messageForm" class="flex items-end gap-3" onsubmit="return false;">
                    <div class="relative">
                        <button id="attachBtn" type="button"
                            class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100">+</button>
                        <input id="attachment" name="attachment" type="file" class="hidden" />
                    </div>

                    <div class="flex-1">
                        <!-- textarea is flex-1 so it shrinks correctly instead of pushing the button off-screen -->
                        <textarea id="body" rows="1" placeholder="Type a message"
                            class="w-full resize-none border rounded px-3 py-2 min-h-[48px] box-border"></textarea>
                        <div id="previewFiles" class="mt-2 flex gap-2"></div>
                    </div>

                    <div class="flex-shrink-0">
                        <!-- make button fixed min width, visible and high z-index if some CSS tries to hide it -->
                        <button id="sendBtn" type="button"
                          class="px-4 py-2 text-white rounded min-w-[72px] z-10"
                          style="background-color: #2563EB !important; color: #ffffff !important; box-shadow: 0 6px 18px rgba(37,99,235,0.18); min-height:48px; display:flex; align-items:center; justify-content:center; border: none; outline: none;">
                          Send
                        </button>
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
        if (window.__chat_script_loaded) { console.warn('chat script already loaded'); } else {
            window.__chat_script_loaded = true;

            // ---------------- safe CSRF (single shared value) ----------------
            if (typeof window._csrfToken === 'undefined') {
                const meta = document.querySelector('meta[name="csrf-token"]');
                window._csrfToken = (meta && meta.content) ? meta.content : '';
            }
            const CHAT_CSRF = window._csrfToken || (document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '');

            // ---------------- blade-passed variables (safe - avoids redeclaration) ----------------
            window.CHAT_CURRENT_USER_ID = (typeof window.CHAT_CURRENT_USER_ID !== 'undefined') ? window.CHAT_CURRENT_USER_ID : {{ auth()->id() }};
            window.CHAT_OTHER_USER_ID = (typeof window.CHAT_OTHER_USER_ID !== 'undefined') ? window.CHAT_OTHER_USER_ID : {{ $user->id }};
            const currentUserId = window.CHAT_CURRENT_USER_ID;
            const otherUserId = window.CHAT_OTHER_USER_ID;
            window.CHAT_USER_PUBLIC_KEY_PEM = (typeof window.CHAT_USER_PUBLIC_KEY_PEM !== 'undefined') ? window.CHAT_USER_PUBLIC_KEY_PEM : @json($user->public_key ?? null);
            const userPublicKeyPEM = window.CHAT_USER_PUBLIC_KEY_PEM;

            // ---------------- prevent overlapping polls ----------------
            let __isLoadingMessages = false;

            // ---------------- cached device private CryptoKey ----------------
            let __devicePrivCryptoKey = null;

            // ----------------- WebCrypto helpers (robust) -----------------
            function arrayBufferToBase64(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                return btoa(binary);
            }

            function base64ToArrayBuffer(base64) {
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

            function pemToBase64(pem) {
                if (!pem || typeof pem !== 'string') return '';
                let s = pem.replace(/-----BEGIN PUBLIC KEY-----/g, '')
                    .replace(/-----END PUBLIC KEY-----/g, '');
                s = s.replace(/\r?\n|\r/g, '');
                s = s.replace(/\\r|\\n/g, '');
                s = s.replace(/\\/g, '');
                s = s.replace(/\s+/g, '');
                return s.trim();
            }

            function quickIsBase64(s) {
                if (!s || typeof s !== 'string') return false;
                const t = s.replace(/\s+/g, '');
                return /^[A-Za-z0-9+\/\-_]+=*$/.test(t);
            }

            async function importRsaPublicKeyFromPem(pemOrBase64) {
                if (!pemOrBase64 || typeof pemOrBase64 !== 'string') {
                    throw new Error('No public key provided to importRsaPublicKeyFromPem');
                }

                let candidate = pemOrBase64;

                if (typeof candidate === 'string' && candidate.includes('-----BEGIN')) {
                    candidate = pemToBase64(candidate);
                } else {
                    candidate = candidate.replace(/\\r|\\n/g, '').replace(/\\/g, '').replace(/\r?\n|\r/g, '').trim();
                }

                candidate = candidate.replace(/-/g, '+').replace(/_/g, '/');
                while (candidate.length % 4 !== 0) candidate += '=';

                if (!quickIsBase64(candidate)) {
                    console.error('importRsaPublicKeyFromPem: input not valid base64/PEM preview:', pemOrBase64.slice(0, 300));
                    throw new Error('Public key is not valid PEM or base64.');
                }

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

                if (typeof axios !== 'undefined') {
                    try {
                        await axios.post("{{ route('user.publickey') }}", {
                            public_key: pubPem,
                            public_key_alg: 'rsa-oaep-sha256'
                        }, { headers: { 'X-CSRF-TOKEN': CHAT_CSRF } });
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

            async function getDevicePrivateCryptoKey() {
                try {
                    if (__devicePrivCryptoKey) return __devicePrivCryptoKey;

                    const pkcs8 = localStorage.getItem(DEVICE_PRIV_KEY_LS);
                    if (!pkcs8) return null;

                    __devicePrivCryptoKey = await importPrivateKeyFromPkcs8(pkcs8);
                    return __devicePrivCryptoKey;
                } catch (err) {
                    console.error('getDevicePrivateCryptoKey error', err);
                    __devicePrivCryptoKey = null;
                    return null;
                }
            }

            async function decryptMessageObj(message) {
                try {
                    if (!message.body || !message.iv || !message.encrypted_keys) {
                        console.debug('decryptMessageObj: missing body/iv/encrypted_keys for message id=', message.id || '(no id)');
                        return null;
                    }

                    let ek = message.encrypted_keys;
                    if (typeof ek === 'string') {
                        try { ek = JSON.parse(ek); } catch (e) { /* ignore */ }
                    }
                    if (!ek || !ek.devices) {
                        console.debug('decryptMessageObj: encrypted_keys structure not as expected', ek);
                        return null;
                    }

                    const isMeSender = (message.sender_id === currentUserId);
                    const wrappedBase64 = isMeSender ? (ek.devices.sender_device || ek.devices.sender) : (ek.devices.receiver_device || ek.devices.receiver);

                    if (!wrappedBase64) {
                        console.warn('No wrapped AES key found for message', message.id || '(no id)', 'encrypted_keys:', ek);
                        return null;
                    }

                    const priv = await getDevicePrivateCryptoKey();
                    if (!priv) {
                        console.warn('Device private key not found — cannot unwrap AES key');
                        const banner = document.getElementById('e2eeStatus');
                        if (banner) {
                            banner.innerText = 'This device does not have your private key — cannot decrypt messages. Open same browser where key was generated or generate device key again.';
                            banner.classList.remove('hidden');
                        }
                        return null;
                    } else {
                        const banner = document.getElementById('e2eeStatus');
                        if (banner) { banner.classList.add('hidden'); banner.innerText = ''; }
                    }

                    let aesRawBuffer;
                    try {
                        aesRawBuffer = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, priv, base64ToArrayBuffer(wrappedBase64));
                    } catch (err) {
                        console.error('RSA unwrap failed for message id=' + (message.id || '(no id)'), err);
                        return null;
                    }

                    const aesCryptoKey = await crypto.subtle.importKey('raw', aesRawBuffer, { name: 'AES-GCM' }, false, ['decrypt']);

                    const ivBuf = base64ToArrayBuffer(message.iv);
                    const ctBuf = base64ToArrayBuffer(message.body);
                    let plainBuf;
                    try {
                        plainBuf = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: ivBuf }, aesCryptoKey, ctBuf);
                    } catch (err) {
                        console.error('AES-GCM decrypt failed for message id=' + (message.id || '(no id)'), err);
                        return null;
                    }

                    const dec = new TextDecoder();
                    return dec.decode(plainBuf);
                } catch (err) {
                    console.error('decryptMessageObj error', err);
                    return null;
                }
            }

            async function decryptAttachmentAndOpen(message) {
                try {
                    if (!message.attachment) { alert('No attachment'); return; }

                    let ek = message.encrypted_keys;
                    if (typeof ek === 'string') { try { ek = JSON.parse(ek); } catch (_) { } }
                    if (!ek || !ek.devices) throw new Error('Missing encrypted_keys');

                    const isMeSender = (message.sender_id === currentUserId);
                    const wrappedBase64 = isMeSender ? (ek.devices.sender_device || ek.devices.sender) : (ek.devices.receiver_device || ek.devices.receiver);
                    if (!wrappedBase64) throw new Error('Wrapped AES key missing');

                    const priv = await getDevicePrivateCryptoKey();
                    if (!priv) throw new Error('Device private key not found');

                    let aesRawBuffer;
                    try {
                        aesRawBuffer = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, priv, base64ToArrayBuffer(wrappedBase64));
                    } catch (err) {
                        console.error('RSA unwrap failed for attachment', err);
                        throw new Error('RSA unwrap failed');
                    }

                    const aesCryptoKey = await crypto.subtle.importKey('raw', aesRawBuffer, { name: 'AES-GCM' }, false, ['decrypt']);

                    const url = `/storage/${message.attachment}`;
                    const res = await fetch(url, { credentials: 'same-origin' });
                    if (!res.ok) throw new Error('Failed to fetch attachment');

                    const encArrayBuffer = await res.arrayBuffer();

                    const fileIvB64 = (message.meta && message.meta.file_iv) ? message.meta.file_iv : null;
                    if (!fileIvB64) throw new Error('Missing file IV in message.meta.file_iv');

                    const ivBuf = base64ToArrayBuffer(fileIvB64);

                    let plainBuffer;
                    try {
                        plainBuffer = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: ivBuf }, aesCryptoKey, encArrayBuffer);
                    } catch (err) {
                        console.error('decryptAttachment failed', err);
                        throw new Error('decryption failed');
                    }

                    const mime = message.mime || (message.meta && message.meta.mime) || 'application/octet-stream';
                    const filename = (message.meta && message.meta.filename) ? message.meta.filename : (message.attachment.split('/').pop() || 'file.bin');

                    const blob = new Blob([plainBuffer], { type: mime });
                    const blobUrl = URL.createObjectURL(blob);

                    const a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(blobUrl);
                } catch (err) {
                    console.error('decryptAttachmentAndOpen error', err);
                    alert('Attachment decrypt failed: ' + (err.message || err));
                }
            }

            // ---------------- Render messages with attempted decryption (no flicker) ----------------
            async function renderMessages(messages) {
                const container = document.getElementById('messages');
                const banner = document.getElementById('e2eeStatus');

                const privExists = !!localStorage.getItem(DEVICE_PRIV_KEY_LS);
                if (!privExists && banner) {
                    banner.innerText = 'This device does not have your private key — cannot decrypt messages. Open same browser where key was generated or generate device key again.';
                    banner.classList.remove('hidden');
                } else if (banner) {
                    banner.classList.add('hidden');
                }

                const decryptPromises = messages.map(async (m) => {
                    if (!m.body || !m.iv || !m.encrypted_keys) return { id: m.id, plain: null, original: m };
                    if (!privExists) return { id: m.id, plain: null, original: m };
                    try {
                        const p = await decryptMessageObj(m);
                        return { id: m.id, plain: p, original: m };
                    } catch (err) {
                        console.error('decryptMessageObj parallel error for id=' + (m.id || '(no id)'), err);
                        return { id: m.id, plain: null, original: m };
                    }
                });

                let results;
                try {
                    results = await Promise.all(decryptPromises);
                } catch (err) {
                    console.error('renderMessages: Promise.all failed', err);
                    results = messages.map(m => ({ id: m.id, plain: null, original: m }));
                }

                // Build HTML in a temporary element (string compare avoids intermediate blank)
                const temp = document.createElement('div');

                for (const r of results) {
                    const m = r.original;
                    const isMe = m.sender_id === currentUserId;

                    const wrapper = document.createElement('div');
                    wrapper.className = 'flex ' + (isMe ? 'justify-end' : 'justify-start');

                    const bubble = document.createElement('div');
                    // Keep tailwind classes but also set inline colors to override external CSS
                    bubble.className = 'p-3 rounded-lg max-w-[60%] break-words whitespace-pre-wrap';
                    // Inline background and text color to ensure visibility
                    if (isMe) {
                        bubble.style.backgroundColor = '#2563EB'; // tailwind bg-blue-600
                        bubble.style.color = '#ffffff';
                    } else {
                        bubble.style.backgroundColor = '#F3F4F6'; // tailwind bg-gray-100
                        bubble.style.color = '#111827'; // tailwind text-gray-900
                    }
                    // ensure these inline rules cannot be overridden easily
                    bubble.style.setProperty('background-color', bubble.style.backgroundColor, 'important');
                    bubble.style.setProperty('color', bubble.style.color, 'important');
                    bubble.style.wordBreak = 'break-word';
                    bubble.style.whiteSpace = 'pre-wrap';

                    // message text (use innerText)
                    if (r.plain) {
                        const p = document.createElement('div');
                        p.innerText = r.plain;
                        p.style.color = bubble.style.color;
                        p.style.wordBreak = 'break-word';
                        p.style.whiteSpace = 'pre-wrap';
                        p.style.setProperty('color', p.style.color, 'important');
                        bubble.appendChild(p);
                    } else if (m.body) {
                        const p = document.createElement('div');
                        p.innerText = '[encrypted message]';
                        p.style.color = bubble.style.color;
                        p.style.setProperty('color', p.style.color, 'important');
                        bubble.appendChild(p);
                    }

                    // attachment button
                    if (m.attachment) {
                        const aWrap = document.createElement('div');
                        aWrap.className = 'mt-2';
                        const link = document.createElement('button');
                        link.type = 'button';
                        link.className = 'underline text-sm bg-transparent';
                        link.innerText = 'Encrypted file — click to decrypt & download';
                        link.addEventListener('click', (ev) => {
                            ev.preventDefault();
                            decryptAttachmentAndOpen(m);
                        });
                        aWrap.appendChild(link);
                        bubble.appendChild(aWrap);
                    }

                    const time = document.createElement('div');
                    time.className = 'text-xs mt-2 text-gray-400 text-right';
                    try {
                        time.innerText = new Date(m.created_at).toLocaleString();
                    } catch (e) {
                        time.innerText = m.created_at || '';
                    }
                    bubble.appendChild(time);

                    wrapper.appendChild(bubble);
                    temp.appendChild(wrapper);
                }

                const newHtml = temp.innerHTML;
                if (container.innerHTML !== newHtml) {
                    const wasNearBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 100;
                    container.innerHTML = newHtml;
                    if (wasNearBottom) {
                        container.scrollTop = container.scrollHeight;
                    }
                }
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

            async function loadMessages() {
                if (typeof axios === 'undefined') {
                    console.warn('axios not available — cannot load messages');
                    return;
                }
                if (__isLoadingMessages) {
                    return;
                }
                __isLoadingMessages = true;
                try {
                    const res = await axios.get(`/chat/${otherUserId}/messages`);
                    const messages = res.data || [];
                    await renderMessages(messages);
                    renderSharedMedia(messages);
                } catch (err) {
                    console.error('loadMessages error', err);
                } finally {
                    __isLoadingMessages = false;
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
                                'X-CSRF-TOKEN': CHAT_CSRF,
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

            // initial load and polling (guard axios) — ensure device key ready first
            (async () => {
                try {
                    await ensureDeviceKey();

                    try {
                        const pkcs8 = localStorage.getItem(DEVICE_PRIV_KEY_LS);
                        if (pkcs8) {
                            __devicePrivCryptoKey = await importPrivateKeyFromPkcs8(pkcs8);
                        }
                    } catch (e) {
                        console.warn('pre-import device private key failed', e);
                    }

                    await loadMessages();
                    setInterval(loadMessages, 5000);
                } catch (err) {
                    console.error('initial setup error', err);
                }
            })();

        } // end chat script guard

    </script>
</x-app-layout>
