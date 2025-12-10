<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">{{ __('Friends & Requests') }}</h2>
    </x-slot>

    <div class="flex gap-6 p-6">

        <!-- LEFT SIDEBAR: FRIENDS LIST -->
        <div class="w-1/4">
            <div class="bg-white p-4 rounded shadow">
                <h3 class="font-medium">Friends</h3>
                <ul id="friendsList" class="mt-3 space-y-2">
                    @foreach($friends as $f)
                        <li>
                            <a href="{{ url('/chat/'.$f->id) }}" class="block p-2 rounded hover:bg-gray-100">
                                {{ $f->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="bg-white p-4 rounded shadow mt-4">
                <h3 class="font-medium">Incoming Requests</h3>
                <div id="incomingList" class="mt-3 space-y-2">
                    @foreach($incoming as $inc)
                    <div id="inc-{{ $inc->id }}" class="flex justify-between items-center p-2 border rounded">
                        <div>
                            <strong>{{ $inc->sender->name }}</strong>
                            <div class="text-xs text-gray-500">{{ $inc->created_at->diffForHumans() }}</div>
                        </div>
                        <div class="space-x-2">
                            <button onclick="acceptRequest({{ $inc->id }})" class="px-2 py-1 bg-green-500 text-white rounded">Accept</button>
                            <button onclick="rejectRequest({{ $inc->id }})" class="px-2 py-1 bg-red-500 text-white rounded">Reject</button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- RIGHT: USERS LIST (ADD FRIEND BUTTONS) -->
        <div class="flex-1">
            <div class="bg-white p-4 rounded shadow">
                <h3 class="font-medium">All Users (Add Friend)</h3>
                <div class="mt-3 space-y-3">
                    @foreach($users as $user)
                        @php
                            $alreadyFriend = auth()->user()->isFriendsWith($user->id);
                            $sentReq = $sent->firstWhere('receiver_id', $user->id);
                            $incomingFromUser = $incoming->firstWhere('sender_id', $user->id);
                        @endphp

                        <div class="flex justify-between items-center p-2 border rounded">
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
                                    <button onclick="cancelRequest({{ $user->id }})"
                                        class="px-3 py-1 bg-gray-300 rounded">
                                        Cancel Request
                                    </button>

                                @else
                                    <button onclick="sendRequest({{ $user->id }})"
                                        class="px-3 py-1 bg-blue-600 text-white rounded">
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


    <script>
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        async function sendRequest(receiverId) {
            await axios.post("{{ route('friends.send') }}", {
                receiver_id: receiverId
            }, {
                headers: { "X-CSRF-TOKEN": csrf }
            });
            location.reload();
        }

        async function cancelRequest(receiverId) {
            await axios.post("{{ route('friends.cancel') }}", {
                receiver_id: receiverId
            }, {
                headers: { "X-CSRF-TOKEN": csrf }
            });
            location.reload();
        }

        async function acceptRequest(reqId) {
            await axios.post("{{ route('friends.accept') }}", {
                request_id: reqId
            }, {
                headers: { "X-CSRF-TOKEN": csrf }
            });
            location.reload();
        }

        async function rejectRequest(reqId) {
            await axios.post("{{ route('friends.reject') }}", {
                request_id: reqId
            }, {
                headers: { "X-CSRF-TOKEN": csrf }
            });
            location.reload();
        }
    </script>

</x-app-layout>
