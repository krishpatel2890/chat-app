{{-- resources/views/friends.blade.php (updated) --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">{{ __('Friends & Requests') }}</h2>
    </x-slot>

    {{-- Fallback small CSS for buttons in case Tailwind is not loaded --}}
    <style>
        .btn { display: inline-block; padding: .4rem .8rem; border-radius: .375rem; cursor: pointer; border: 1px solid #ddd; background:#f3f4f6; }
        .btn-primary { background:#2563eb; color:#fff; border-color: #2563eb; }
        .btn-danger { background:#ef4444; color:#fff; border-color:#ef4444; }
        .btn-success { background:#16a34a; color:#fff; border-color:#16a34a; }
        .muted { color:#6b7280; font-size:.85rem; }
        .card { background:#fff; padding:1rem; border-radius:.5rem; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .space-y-2 > * + * { margin-top:.5rem; }
    </style>

    <div class="flex gap-6 p-6">
        <!-- LEFT SIDEBAR: FRIENDS LIST -->
        <div class="w-1/4">
            <div class="card">
                <h3 class="font-medium">Friends</h3>
                <ul id="friendsList" class="mt-3 space-y-2">
                    @forelse($friends as $f)
                        <li>
                            <a href="{{ url('/chat/'.$f->id) }}" class="block p-2 rounded hover:bg-gray-100">
                                {{ $f->name }}
                            </a>
                        </li>
                    @empty
                        <li class="muted">No friends yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="card mt-4">
                <h3 class="font-medium">Incoming Requests</h3>
                <div id="incomingList" class="mt-3 space-y-2">
                    @forelse($incoming as $inc)
                        <div id="inc-{{ $inc->id }}" class="flex justify-between items-center p-2 border rounded">
                            <div>
                                <strong>{{ $inc->sender->name }}</strong>
                                <div class="text-xs text-gray-500">{{ $inc->created_at->diffForHumans() }}</div>
                            </div>
                            <div class="space-x-2">
                                <button onclick="acceptRequest({{ $inc->id }})" class="btn btn-success">Accept</button>
                                <button onclick="rejectRequest({{ $inc->id }})" class="btn btn-danger">Reject</button>
                            </div>
                        </div>
                    @empty
                        <div class="muted">No incoming requests.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- RIGHT: USERS LIST (ADD FRIEND BUTTONS) -->
        <div class="flex-1">
            <div class="card">
                <h3 class="font-medium">All Users (Add Friend)</h3>
                <div class="mt-3 space-y-3">
                    @foreach($users as $user)
                        @if($user->id === auth()->id())
                            {{-- don't show self --}}
                            @continue
                        @endif

                        @php
                            $alreadyFriend = auth()->user()->isFriendsWith($user->id);
                            $sentReq = $sent->firstWhere('receiver_id', $user->id);
                            $incomingFromUser = $incoming->firstWhere('sender_id', $user->id);
                        @endphp

                        <div id="user-{{ $user->id }}" class="flex justify-between items-center p-2 border rounded">
                            <div>
                                <strong>{{ $user->name }}</strong>
                                <div class="text-xs text-gray-500">{{ $user->email }}</div>
                            </div>

                            <div>
                                @if($alreadyFriend)
                                    <span class="text-green-600 font-medium">Friend</span>

                                @elseif($incomingFromUser)
                                    <span class="text-yellow-600">Sent you request</span>

                                @elseif($sentReq)
                                    <button onclick="cancelRequest({{ $user->id }})" class="btn">
                                        Cancel Request
                                    </button>

                                @else
                                    <button onclick="sendRequest({{ $user->id }})" class="btn btn-primary">
                                        Add Friend
                                    </button>
                                @endif
                            </div>
                        </div>

                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Simple toast / feedback container --}}
    <div id="toast" style="position:fixed; right:20px; bottom:20px; z-index:9999;"></div>

    {{-- Ensure CSRF token meta exists or fallback --}}
    @if(!\Illuminate\Support\Str::contains(\Illuminate\Support\Facades\View::getSections()['header'] ?? '', 'csrf-token'))
        {{-- If your layout already outputs meta csrf, this is fine; otherwise we output a fallback --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">
    @endif

    {{-- Axios CDN (only if axios not already loaded globally) --}}
    <script>
        if (typeof axios === 'undefined') {
            let s = document.createElement('script');
            s.src = "https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js";
            s.defer = true;
            document.head.appendChild(s);
        }
    </script>

    <script>
        // Helper to show small toast messages
        function showToast(message, timeout = 3000) {
            const t = document.createElement('div');
            t.textContent = message;
            t.style.background = 'rgba(0,0,0,0.75)';
            t.style.color = '#fff';
            t.style.padding = '8px 12px';
            t.style.borderRadius = '6px';
            t.style.marginTop = '8px';
            document.getElementById('toast').appendChild(t);
            setTimeout(() => t.remove(), timeout);
        }

        // Get CSRF token from meta tag or from blade fallback
        function getCsrf() {
            const m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.content : '{{ csrf_token() }}';
        }

        const csrf = getCsrf();

        async function sendRequest(receiverId) {
            try {
                await axios.post("{{ route('friends.send') }}", {
                    receiver_id: receiverId
                }, {
                    headers: { "X-CSRF-TOKEN": csrf }
                });
                showToast('Request sent');
                // Optimistic UI: change button to "Cancel Request" without full reload
                const userDiv = document.getElementById('user-' + receiverId);
                if (userDiv) {
                    const btnContainer = userDiv.querySelector('div:last-child');
                    btnContainer.innerHTML = `<button onclick="cancelRequest(${receiverId})" class="btn">Cancel Request</button>`;
                } else {
                    location.reload();
                }
            } catch (err) {
                console.error(err);
                showToast('Error sending request');
            }
        }

        async function cancelRequest(receiverId) {
            try {
                await axios.post("{{ route('friends.cancel') }}", {
                    receiver_id: receiverId
                }, {
                    headers: { "X-CSRF-TOKEN": csrf }
                });
                showToast('Request cancelled');
                const userDiv = document.getElementById('user-' + receiverId);
                if (userDiv) {
                    const btnContainer = userDiv.querySelector('div:last-child');
                    btnContainer.innerHTML = `<button onclick="sendRequest(${receiverId})" class="btn btn-primary">Add Friend</button>`;
                } else {
                    location.reload();
                }
            } catch (err) {
                console.error(err);
                showToast('Error cancelling request');
            }
        }

        async function acceptRequest(reqId) {
            try {
                await axios.post("{{ route('friends.accept') }}", {
                    request_id: reqId
                }, {
                    headers: { "X-CSRF-TOKEN": csrf }
                });
                showToast('Request accepted');
                const inc = document.getElementById('inc-' + reqId);
                if (inc) inc.remove();
                // Optionally add to friends list UI
                location.reload();
            } catch (err) {
                console.error(err);
                showToast('Error accepting request');
            }
        }

        async function rejectRequest(reqId) {
            try {
                await axios.post("{{ route('friends.reject') }}", {
                    request_id: reqId
                }, {
                    headers: { "X-CSRF-TOKEN": csrf }
                });
                showToast('Request rejected');
                const inc = document.getElementById('inc-' + reqId);
                if (inc) inc.remove();
            } catch (err) {
                console.error(err);
                showToast('Error rejecting request');
            }
        }

        // Defensive: if axios is loaded async, wait until available before using (rare)
        (function waitForAxios(){
            if (typeof axios === 'undefined') {
                setTimeout(waitForAxios, 50);
                return;
            }
            // axios is ready
        })();
    </script>
</x-app-layout>
