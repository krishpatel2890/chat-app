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
                        {{ strtoupper(substr($user->name,0,1)) }}
                    </div>
                </div>
                <div><div class="font-medium">{{ $user->name }}</div></div>
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
                <hr class="my-3"/>
                <h4 class="font-medium">Shared Media</h4>
                <div id="sharedMedia" class="mt-2 grid grid-cols-2 gap-2"></div>
            </div>
        </div>
    </div>

    <script>
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const currentUserId = {{ auth()->id() }};
    const otherUserId = {{ $user->id }};
    const userPublicKeyPEM = `{{ addslashes($user->public_key ?? '') }}`; // may be empty

    // ------- WebCrypto helper functions (RSA-OAEP + AES-GCM) -------

    // Convert PEM (SPKI) to CryptoKey
    async function importRsaPublicKeyFromPem(pem) {
        if (!pem) return null;
        // strip header/footer
        const b64 = pem.replace(/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/g, '');
        const binary = atob(b64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return await crypto.subtle.importKey(
            'spki',
            bytes.buffer,
            { name: 'RSA-OAEP', hash: 'SHA-256' },
            true,
            ['encrypt']
        );
    }

    // Export RSA public key (CryptoKey) to PEM
    async function exportRsaPublicKeyToPem(key) {
        const spki = await crypto.subtle.exportKey('spki', key);
        const b64 = arrayBufferToBase64(spki);
        const pem = `-----BEGIN PUBLIC KEY-----\n${b64.match(/.{1,64}/g).join('\n')}\n-----END PUBLIC KEY-----`;
        return pem;
    }

    // Import RSA private key from PKCS8 (for local storage use)
    async function importPrivateKeyFromPkcs8(p8) {
        const binary = base64ToArrayBuffer(p8);
        return await crypto.subtle.importKey('pkcs8', binary, { name: 'RSA-OAEP', hash:'SHA-256' }, true, ['decrypt']);
    }

    async function exportPrivateKeyToPkcs8(key) {
        const pkcs8 = await crypto.subtle.exportKey('pkcs8', key);
        return arrayBufferToBase64(pkcs8);
    }

    // utils
    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let b of bytes) binary += String.fromCharCode(b);
        return btoa(binary);
    }
    function base64ToArrayBuffer(base64) {
        const binary = atob(base64);
        const len = binary.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    // store/retrieve device keys in localStorage (for demo)
    const DEVICE_PRIV_KEY_LS = 'e2ee_device_priv_pkcs8';
    const DEVICE_PUB_KEY_LS = 'e2ee_device_pub_spki';

    // generate a RSA keypair for the device and return {pubPem, privPkcs8Base64}
    async function generateAndStoreDeviceKeypair() {
        const keyPair = await crypto.subtle.generateKey(
            { name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1,0,1]), hash: 'SHA-256' },
            true,
            ['encrypt','decrypt']
        );
        const pubPem = await exportRsaPublicKeyToPem(keyPair.publicKey);
        const privPkcs8 = await exportPrivateKeyToPkcs8(keyPair.privateKey);

        localStorage.setItem(DEVICE_PRIV_KEY_LS, privPkcs8);
        localStorage.setItem(DEVICE_PUB_KEY_LS, pubPem);

        // upload public key to server so other devices can fetch
        await axios.post("{{ route('user.publickey') }}", {
            public_key: pubPem,
            public_key_alg: 'rsa-oaep-sha256'
        }, { headers: { 'X-CSRF-TOKEN': csrf }});

        return {pubPem, privPkcs8};
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

    // wrap (encrypt) raw AES key with recipient public key (RSA-OAEP)
    async function wrapAesKeyWithRsa(aesRawBuffer, recipientPublicKeyCrypto) {
        // recipientPublicKeyCrypto is a CryptoKey (RSA-OAEP)
        const wrapped = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, recipientPublicKeyCrypto, aesRawBuffer);
        return arrayBufferToBase64(wrapped);
    }

    // create AES-GCM key and return {key, raw: base64}
    async function createAesKey() {
        const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt','decrypt']);
        const raw = await crypto.subtle.exportKey('raw', key);
        return { key, rawBase64: arrayBufferToBase64(raw) };
    }

    // encrypt plain text with AES-GCM => returns {cipherBase64, ivBase64}
    async function encryptWithAesGcm(aesKey, plaintext) {
        const iv = crypto.getRandomValues(new Uint8Array(12)); // 96-bit
        const enc = new TextEncoder();
        const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, enc.encode(plaintext));
        return { cipherBase64: arrayBufferToBase64(ct), ivBase64: arrayBufferToBase64(iv) };
    }

    // encrypt ArrayBuffer / Blob with AES-GCM (returns base64)
    async function encryptBlobWithAesGcm(aesKey, arrayBuffer) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aesKey, arrayBuffer);
        return { cipherBase64: arrayBufferToBase64(ct), ivBase64: arrayBufferToBase64(iv) };
    }

    // decrypt helpers (for completeness, not required server-side)
    async function importDevicePrivateKeyFromLocal() {
        const pkcs8 = localStorage.getItem(DEVICE_PRIV_KEY_LS);
        if (!pkcs8) return null;
        return await importPrivateKeyFromPkcs8(pkcs8);
    }

    // ------------- Chat logic: load messages and send encrypted --------------

    async function loadMessages() {
        const res = await axios.get(`/chat/${otherUserId}/messages`);
        const messages = res.data;
        renderMessages(messages);
        renderSharedMedia(messages);
        scrollBottom();
    }

    function renderMessages(messages) {
        const container = document.getElementById('messages');
        container.innerHTML = '';
        messages.forEach(m => {
            const isMe = m.sender_id === currentUserId;
            const wrapper = document.createElement('div');
            wrapper.className = 'flex ' + (isMe ? 'justify-end' : 'justify-start');
            const bubble = document.createElement('div');
            bubble.className = (isMe ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900') + ' p-3 rounded-lg max-w-[60%]';
            // body is ciphertext - we show "[encrypted]" placeholder; client must decrypt locally to show plaintext
            if (m.body) {
                const p = document.createElement('div');
                p.innerText = '[encrypted message]'; // we do NOT decrypt here — you must call client decryption
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

    // attachment UI
    document.getElementById('attachBtn').addEventListener('click', () => {
        document.getElementById('attachment').click();
    });

    document.getElementById('attachment').addEventListener('change', (e) => {
        const preview = document.getElementById('previewFiles');
        preview.innerHTML = '';
        const file = e.target.files[0];
        if (!file) return;
        if (file.type.startsWith('image')) {
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

    // send button — encrypt before uploading
    document.getElementById('sendBtn').addEventListener('click', async () => {
        const plain = document.getElementById('body').value.trim();
        const file = document.getElementById('attachment').files[0];

        if (!plain && !file) return;

        // Ensure device keypair exists and uploaded to server
        const deviceKeys = await ensureDeviceKey();
        const devicePriv = await importDevicePrivateKeyFromLocal(); // for decrypting later
        const devicePubPem = deviceKeys.pubPem;

        // get recipient public key from server: we rely on userPublicKeyPEM variable set by Blade
        let recipientPubPem = userPublicKeyPEM;
        if (!recipientPubPem) {
            alert('Recipient does not have a public key registered yet.');
            return;
        }
        const recipientPubKey = await importRsaPublicKeyFromPem(recipientPubPem);

        // Create AES key and encrypt plaintext
        const { key: aesKey, rawBase64: aesRawBase64 } = await createAesKey();

        // encrypt message text (if any)
        let cipherTextBase64 = null;
        let ivBase64ForText = null;
        if (plain) {
            const encRes = await encryptWithAesGcm(aesKey, plain);
            cipherTextBase64 = encRes.cipherBase64;
            ivBase64ForText = encRes.ivBase64;
        }

        // encrypt file (if any) using the same AES key
        let attachmentBlob = null;
        let attachmentMime = null;
        if (file) {
            const arrayBuffer = await file.arrayBuffer();
            const encFile = await encryptBlobWithAesGcm(aesKey, arrayBuffer);
            // convert cipherBase64 to Blob for sending
            const cipherBuffer = base64ToArrayBuffer(encFile.cipherBase64);
            attachmentBlob = new Blob([cipherBuffer], { type: 'application/octet-stream' });
            attachmentMime = file.type;
            // we also need to send iv for the file; we'll store file iv inside meta below
            var fileIvBase64 = encFile.ivBase64;
        }

        // Export raw AES key to wrap it with recipient's public key
        const aesRaw = base64ToArrayBuffer(aesRawBase64);

        // wrap AES key for recipient
        const wrappedForRecipient = await wrapAesKeyWithRsa(aesRaw, recipientPubKey);

        // also wrap AES key for sender (so this device or other devices can decrypt), using device public key
        const devicePubCrypto = await importRsaPublicKeyFromPem(devicePubPem);
        const wrappedForSender = await wrapAesKeyWithRsa(aesRaw, devicePubCrypto);

        // Build encrypted_keys JSON: mapping simple names (receiver, sender-device)
        const encryptedKeys = {
            devices: {
                receiver_device: wrappedForRecipient,
                sender_device: wrappedForSender
            },
            algorithm: 'rsa-oaep-sha256'
        };

        // meta: include file iv if file present, and original filename (optionally encrypted — here left as plain for demo)
        const meta = {};
        if (file) {
            meta.filename = file.name;
            meta.file_iv = fileIvBase64;
        }

        // Build FormData to send to server
        const fd = new FormData();
        fd.append('receiver_id', otherUserId);
        if (cipherTextBase64) fd.append('body', cipherTextBase64);
        if (ivBase64ForText) fd.append('iv', ivBase64ForText);
        if (null !== file) {
            fd.append('attachment', attachmentBlob, file.name + '.enc'); // store encrypted blob with .enc suffix
            fd.append('mime', attachmentMime);
        }
        fd.append('enc_algo', 'aes-256-gcm');
        fd.append('encrypted_keys', JSON.stringify(encryptedKeys));
        fd.append('meta', JSON.stringify(meta));

        try {
            await axios.post("{{ route('chat.send') }}", fd, {
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'multipart/form-data'
                }
            });

            // clear UI
            document.getElementById('body').value = '';
            document.getElementById('attachment').value = '';
            document.getElementById('previewFiles').innerHTML = '';

            // reload messages
            loadMessages();
        } catch (err) {
            console.error(err);
            alert('Send failed');
        }
    });

    // initial load and polling
    loadMessages();
    setInterval(loadMessages, 5000);
    // ensure device key exists when user opens chat
    ensureDeviceKey().catch(err => console.error('device key error', err));
    </script>
</x-app-layout>
