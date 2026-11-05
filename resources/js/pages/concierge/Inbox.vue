<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bot,
    BookOpen,
    Check,
    CircleDot,
    Clock,
    MessageSquare,
    PauseCircle,
    PlayCircle,
    Search,
    Send,
    Sparkles,
    User,
    UserCog,
    X,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Branch {
    id: number;
    name: string;
    currency_code: string;
}

interface ThreadReservation {
    id: number;
    confirmation_number: string;
    status: string;
    check_in_date: string | null;
    check_out_date: string | null;
}

interface ThreadSummary {
    id: number;
    guest_name: string;
    wa_id: string;
    status: string;
    unread_count: number;
    last_message: string | null;
    last_message_at: string | null;
    handoff_at: string | null;
    reservation: ThreadReservation | null;
}

interface Message {
    id: number;
    role: string;
    body: string;
    tool_calls: Array<Record<string, unknown>>;
    created_at: string | null;
}

interface Action {
    id: number;
    type: string;
    status: string;
    payload: Record<string, unknown>;
    created_at: string | null;
}

interface SelectedThread {
    id: number;
    guest_name: string;
    wa_id: string;
    status: string;
    reservation_id: number | null;
    assigned_to: number | null;
    messages: Message[];
    actions: Action[];
}

interface KbEntry {
    id: number;
    topic: string;
    question: string | null;
    answer: string;
    keywords: string[] | null;
    active: boolean;
}

const props = defineProps<{
    branch: Branch;
    threads: ThreadSummary[];
    selected: SelectedThread | null;
    status: string | null;
    whatsapp: { enabled: boolean; configured: boolean };
    kb: KbEntry[];
    can: { reply: boolean; manage_kb: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Concierge', href: '/concierge' },
        ],
    },
});

const view = ref<'inbox' | 'kb'>(initialView());
const search = ref('');
const body = ref('');
const chatScroll = ref<HTMLElement | null>(null);
const sending = ref(false);
const suggesting = ref(false);
const suggestionError = ref<string | null>(null);

const editing = ref<KbEntry | null>(null);
const kbDialogOpen = ref(false);
const kbForm = ref({
    topic: '',
    question: '',
    answer: '',
    keywords: '',
    active: true,
});

function initialView(): 'inbox' | 'kb' {
    if (typeof window === 'undefined') return 'inbox';

    return new URLSearchParams(window.location.search).get('tab') === 'kb'
        ? 'kb'
        : 'inbox';
}

const statusTabs = [
    { label: 'All', value: null },
    { label: 'AI active', value: 'active' },
    { label: 'Human', value: 'handoff' },
    { label: 'Closed', value: 'closed' },
];

const filteredThreads = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') return props.threads;

    return props.threads.filter(
        (thread) =>
            thread.guest_name.toLowerCase().includes(term) ||
            thread.wa_id.includes(term) ||
            (thread.last_message ?? '').toLowerCase().includes(term),
    );
});

const pendingActions = computed(
    () =>
        props.selected?.actions.filter(
            (action) =>
                action.status === 'pending_guest_confirm' ||
                action.status === 'guest_confirmed',
        ) ?? [],
);

const remainingActions = computed(
    () =>
        props.selected?.actions.filter(
            (action) =>
                action.status !== 'pending_guest_confirm' &&
                action.status !== 'guest_confirmed',
        ) ?? [],
);

const formatTime = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '—';

const formatMinor = (minor: unknown): string => {
    const value = Number(minor);

    if (!Number.isFinite(value)) return '—';

    return `${(value / 100).toFixed(2)} ${props.branch.currency_code}`;
};

const goToThread = (id: number) => {
    router.get(
        `/branches/${props.branch.id}/concierge`,
        props.status ? { thread: id, status: props.status } : { thread: id },
        { preserveState: true, preserveScroll: true },
    );
};

const setStatus = (status: string | null) => {
    router.get(
        `/branches/${props.branch.id}/concierge`,
        status ? { status } : {},
        { preserveState: true, preserveScroll: true },
    );
};

const takeOver = () => {
    if (!props.selected) return;
    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/takeover`,
        {},
        { preserveScroll: true },
    );
};

const handBack = () => {
    if (!props.selected) return;
    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/handback`,
        {},
        { preserveScroll: true },
    );
};

const closeThread = () => {
    if (!props.selected) return;
    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/close`,
        {},
        { preserveScroll: true },
    );
};

const sendReply = () => {
    if (!props.selected || body.value.trim() === '' || sending.value) return;

    sending.value = true;

    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/messages`,
        { body: body.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                body.value = '';
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
};

const suggestReply = async () => {
    if (!props.selected || suggesting.value) return;

    suggesting.value = true;
    suggestionError.value = null;

    try {
        const token =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';

        const response = await fetch(
            `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/suggest`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            },
        );

        if (!response.ok) {
            throw new Error('Suggestion request failed.');
        }

        const data = (await response.json()) as { suggestion: string | null };

        if (data.suggestion) {
            body.value = data.suggestion;
        } else {
            suggestionError.value =
                'No draft available right now. Write the reply yourself.';
        }
    } catch {
        suggestionError.value =
            'Could not draft a reply right now. Write it yourself.';
    } finally {
        suggesting.value = false;
    }
};

const toggleKillSwitch = () => {
    router.put(
        `/branches/${props.branch.id}/concierge/settings`,
        { enabled: !props.whatsapp.enabled },
        { preserveScroll: true },
    );
};

const applyAction = (id: number) => {
    if (!props.selected) return;
    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/actions/${id}/apply`,
        {},
        { preserveScroll: true },
    );
};

const rejectAction = (id: number) => {
    if (!props.selected) return;
    router.post(
        `/branches/${props.branch.id}/concierge/threads/${props.selected.id}/actions/${id}/reject`,
        {},
        { preserveScroll: true },
    );
};

const scrollToBottom = async () => {
    await nextTick();
    if (chatScroll.value) {
        chatScroll.value.scrollTop = chatScroll.value.scrollHeight;
    }
};

watch(
    () => props.selected?.messages.length,
    () => void scrollToBottom(),
);

watch(
    () => props.selected?.id,
    () => {
        suggestionError.value = null;
    },
);

onMounted(() => void scrollToBottom());

const roleLabel = (role: string): string => {
    if (role === 'assistant') return 'AI assistant';
    if (role === 'agent') return 'Front desk';
    if (role === 'guest') return 'Guest';

    return role;
};

const bubbleClass = (role: string): string => {
    if (role === 'guest') {
        return 'mr-auto bg-muted text-foreground';
    }

    if (role === 'agent') {
        return 'ml-auto bg-primary text-primary-foreground';
    }

    return 'ml-auto bg-card border border-border text-foreground';
};

const statusClass = (status: string): string => {
    if (status === 'handoff') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200';
    }

    if (status === 'closed') {
        return 'bg-muted text-muted-foreground';
    }

    return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
};

const actionStatusClass = (status: string): string => {
    if (status === 'applied') {
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    }

    if (status === 'rejected') {
        return 'bg-muted text-muted-foreground';
    }

    return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
};

const actionLabel = (type: string): string =>
    type.replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase());

const openNewEntry = () => {
    editing.value = null;
    kbForm.value = {
        topic: '',
        question: '',
        answer: '',
        keywords: '',
        active: true,
    };
    kbDialogOpen.value = true;
};

const openEditEntry = (entry: KbEntry) => {
    editing.value = entry;
    kbForm.value = {
        topic: entry.topic,
        question: entry.question ?? '',
        answer: entry.answer,
        keywords: (entry.keywords ?? []).join(', '),
        active: entry.active,
    };
    kbDialogOpen.value = true;
};

const saveEntry = () => {
    const payload = {
        topic: kbForm.value.topic,
        question: kbForm.value.question,
        answer: kbForm.value.answer,
        keywords: kbForm.value.keywords
            .split(',')
            .map((keyword) => keyword.trim())
            .filter((keyword) => keyword !== ''),
        active: kbForm.value.active,
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            kbDialogOpen.value = false;
        },
    };

    if (editing.value) {
        router.put(
            `/branches/${props.branch.id}/concierge/kb/${editing.value.id}`,
            payload,
            options,
        );

        return;
    }

    router.post(
        `/branches/${props.branch.id}/concierge/kb`,
        payload,
        options,
    );
};

const deleteEntry = (entry: KbEntry) => {
    router.delete(
        `/branches/${props.branch.id}/concierge/kb/${entry.id}`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="WhatsApp Concierge" />

    <div class="flex h-[calc(100vh-4rem)] flex-col p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    WhatsApp Concierge — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Direct Meta Cloud API connection.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    variant="outline"
                    :class="
                        whatsapp.configured
                            ? 'border-green-300 text-green-700 dark:text-green-300'
                            : 'border-red-300 text-red-700 dark:text-red-300'
                    "
                >
                    <CircleDot class="mr-1 size-3" />
                    {{ whatsapp.configured ? 'Connected' : 'Not configured' }}
                </Badge>

                <Button
                    v-if="can.manage_kb"
                    size="sm"
                    variant="outline"
                    @click="toggleKillSwitch"
                >
                    <component
                        :is="whatsapp.enabled ? PauseCircle : PlayCircle"
                        class="mr-1 size-4"
                    />
                    {{ whatsapp.enabled ? 'Pause AI' : 'Resume AI' }}
                </Button>

                <Button
                    size="sm"
                    :variant="view === 'inbox' ? 'default' : 'outline'"
                    @click="view = 'inbox'"
                >
                    <MessageSquare class="mr-1 size-4" /> Inbox
                </Button>
                <Button
                    v-if="can.manage_kb"
                    size="sm"
                    :variant="view === 'kb' ? 'default' : 'outline'"
                    @click="view = 'kb'"
                >
                    <BookOpen class="mr-1 size-4" /> Knowledge base
                </Button>
            </div>
        </div>

        <div v-if="view === 'inbox'" class="flex min-h-0 flex-1 gap-4">
            <aside class="border-border flex w-80 shrink-0 flex-col rounded-lg border">
                <div class="border-border border-b p-3">
                    <div class="relative">
                        <Search
                            class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                        />
                        <Input
                            v-model="search"
                            class="pl-8"
                            placeholder="Search guest or number"
                        />
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <Button
                            v-for="tab in statusTabs"
                            :key="tab.label"
                            size="sm"
                            :variant="
                                status === tab.value ? 'default' : 'ghost'
                            "
                            @click="setStatus(tab.value)"
                        >
                            {{ tab.label }}
                        </Button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button
                        v-for="thread in filteredThreads"
                        :key="thread.id"
                        type="button"
                        class="border-border hover:bg-muted/60 flex w-full flex-col gap-1 border-b p-3 text-left"
                        :class="
                            selected?.id === thread.id
                                ? 'bg-muted'
                                : 'bg-transparent'
                        "
                        @click="goToThread(thread.id)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-foreground truncate text-sm font-semibold">
                                {{ thread.guest_name }}
                            </span>
                            <Badge variant="outline" :class="statusClass(thread.status)">
                                {{ thread.status }}
                            </Badge>
                        </div>
                        <div class="text-muted-foreground truncate text-xs">
                            {{ thread.last_message ?? 'No messages yet' }}
                        </div>
                        <div class="text-muted-foreground flex items-center justify-between text-xs">
                            <span>{{ thread.wa_id }}</span>
                            <span class="flex items-center gap-1">
                                <Badge
                                    v-if="thread.unread_count > 0"
                                    class="bg-primary text-primary-foreground"
                                >
                                    {{ thread.unread_count }}
                                </Badge>
                                {{ formatTime(thread.last_message_at) }}
                            </span>
                        </div>
                    </button>

                    <p
                        v-if="filteredThreads.length === 0"
                        class="text-muted-foreground p-4 text-center text-sm"
                    >
                        No conversations match this filter.
                    </p>
                </div>
            </aside>

            <section
                v-if="selected"
                class="border-border flex min-w-0 flex-1 flex-col rounded-lg border"
            >
                <header class="border-border flex flex-wrap items-center justify-between gap-2 border-b p-3">
                    <div>
                        <div class="text-foreground flex items-center gap-2 font-semibold">
                            {{ selected.guest_name }}
                            <Badge variant="outline" :class="statusClass(selected.status)">
                                {{ selected.status }}
                            </Badge>
                        </div>
                        <div class="text-muted-foreground text-xs">
                            {{ selected.wa_id }}
                            <template v-if="selected.reservation_id">
                                · Res #{{ selected.reservation_id }}
                            </template>
                        </div>
                    </div>

                    <div v-if="can.reply" class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="selected.status === 'handoff'"
                            size="sm"
                            variant="outline"
                            @click="handBack"
                        >
                            <Bot class="mr-1 size-4" /> Hand back to AI
                        </Button>
                        <Button v-else size="sm" variant="outline" @click="takeOver">
                            <UserCog class="mr-1 size-4" /> Take over
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="closeThread"
                        >
                            <X class="mr-1 size-4" /> Close
                        </Button>
                    </div>
                </header>

                <div
                    v-if="pendingActions.length > 0"
                    class="border-border bg-muted/30 space-y-2 border-b p-3"
                >
                    <div
                        v-for="action in pendingActions"
                        :key="action.id"
                        class="border-border flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background p-2"
                    >
                        <div class="flex items-center gap-2 text-sm">
                            <AlertTriangle class="size-4 text-amber-600" />
                            <span class="text-foreground font-medium">
                                {{ actionLabel(action.type) }}
                            </span>
                            <span class="text-muted-foreground">
                                {{ formatMinor(action.payload.fee_minor) }}
                            </span>
                            <Badge variant="outline" :class="actionStatusClass(action.status)">
                                {{ action.status.replaceAll('_', ' ') }}
                            </Badge>
                        </div>
                        <div v-if="can.reply && action.status === 'guest_confirmed'" class="flex gap-2">
                            <Button size="sm" @click="applyAction(action.id)">
                                <Check class="mr-1 size-4" /> Apply
                            </Button>
                            <Button size="sm" variant="outline" @click="rejectAction(action.id)">
                                Dismiss
                            </Button>
                        </div>
                        <div v-else-if="can.reply && action.status === 'pending_guest_confirm'">
                            <Button size="sm" variant="outline" @click="rejectAction(action.id)">
                                Dismiss
                            </Button>
                        </div>
                    </div>
                </div>

                <div ref="chatScroll" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                    <div
                        v-for="message in selected.messages"
                        :key="message.id"
                        class="flex max-w-[80%] flex-col gap-1"
                        :class="
                            message.role === 'guest'
                                ? 'mr-auto items-start'
                                : 'ml-auto items-end'
                        "
                    >
                        <div
                            class="flex items-center gap-1 text-xs"
                            :class="
                                message.role === 'guest'
                                    ? 'text-muted-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            <User v-if="message.role === 'guest'" class="size-3" />
                            <Bot v-else-if="message.role === 'assistant'" class="size-3" />
                            <UserCog v-else class="size-3" />
                            {{ roleLabel(message.role) }}
                            <span>·</span>
                            <Clock class="size-3" />
                            {{ formatTime(message.created_at) }}
                        </div>
                        <div
                            class="rounded-lg px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="bubbleClass(message.role)"
                        >
                            {{ message.body }}
                        </div>
                    </div>

                    <p
                        v-if="selected.messages.length === 0"
                        class="text-muted-foreground py-10 text-center text-sm"
                    >
                        No messages in this thread yet.
                    </p>
                </div>

                <div v-if="remainingActions.length > 0" class="border-border border-t px-3 py-2">
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="action in remainingActions"
                            :key="action.id"
                            variant="outline"
                            :class="actionStatusClass(action.status)"
                        >
                            {{ actionLabel(action.type) }}:
                            {{ action.status.replaceAll('_', ' ') }}
                        </Badge>
                    </div>
                </div>

                <div class="border-border border-t p-3">
                    <div v-if="!can.reply" class="text-muted-foreground text-sm">
                        You can view this conversation but not reply.
                    </div>
                    <template v-else>
                        <div
                            v-if="selected.status === 'handoff'"
                            class="mb-2 flex items-center justify-between gap-2"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="suggesting"
                                @click="suggestReply"
                            >
                                <Sparkles class="size-4" />
                                {{ suggesting ? 'Drafting…' : 'Draft with AI' }}
                            </Button>
                            <p
                                v-if="suggestionError"
                                class="text-muted-foreground text-xs"
                            >
                                {{ suggestionError }}
                            </p>
                        </div>
                        <form
                            class="flex items-end gap-2"
                            @submit.prevent="sendReply"
                        >
                            <textarea
                                v-model="body"
                                rows="2"
                                class="border-input bg-background text-foreground placeholder:text-muted-foreground w-full resize-none rounded-md border px-3 py-2 text-sm focus:outline-none"
                                :placeholder="
                                    selected.status === 'handoff'
                                        ? 'Reply as front desk…'
                                        : 'Take over to reply manually, or let the AI answer.'
                                "
                                :disabled="selected.status !== 'handoff'"
                            />
                            <Button
                                type="submit"
                                :disabled="
                                    sending ||
                                    body.trim() === '' ||
                                    selected.status !== 'handoff'
                                "
                            >
                                <Send class="mr-1 size-4" /> Send
                            </Button>
                        </form>
                        <p
                            v-if="selected.status !== 'handoff'"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            The AI assistant is handling this thread. Take over to type a manual reply.
                        </p>
                    </template>
                </div>
            </section>

            <section
                v-else
                class="border-border text-muted-foreground flex flex-1 items-center justify-center rounded-lg border text-sm"
            >
                Select a conversation to view the transcript.
            </section>
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-foreground text-lg font-semibold">
                        Knowledge base
                    </h2>
                    <p class="text-muted-foreground text-sm">
                        The AI only answers from these entries. Anything else goes to a human.
                    </p>
                </div>
                <Button @click="openNewEntry">
                    <BookOpen class="mr-1 size-4" /> Add entry
                </Button>
            </div>

            <div class="border-border rounded-lg border">
                <table class="w-full caption-bottom text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="text-muted-foreground h-12 px-4 text-left font-medium">
                                Topic
                            </th>
                            <th class="text-muted-foreground h-12 px-4 text-left font-medium">
                                Answer
                            </th>
                            <th class="text-muted-foreground h-12 px-4 text-left font-medium">
                                Keywords
                            </th>
                            <th class="text-muted-foreground h-12 px-4 text-left font-medium">
                                Active
                            </th>
                            <th class="text-muted-foreground h-12 px-4 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in kb"
                            :key="entry.id"
                            class="border-border hover:bg-muted/50 border-t"
                        >
                            <td class="text-foreground p-4 font-medium">
                                {{ entry.topic }}
                            </td>
                            <td class="text-muted-foreground max-w-md truncate p-4">
                                {{ entry.answer }}
                            </td>
                            <td class="text-muted-foreground p-4 text-xs">
                                {{ (entry.keywords ?? []).join(', ') }}
                            </td>
                            <td class="p-4">
                                <Badge
                                    variant="outline"
                                    :class="
                                        entry.active
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ entry.active ? 'Yes' : 'No' }}
                                </Badge>
                            </td>
                            <td class="p-4">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="openEditEntry(entry)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="deleteEntry(entry)"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="kb.length === 0">
                            <td colspan="5" class="text-muted-foreground p-6 text-center">
                                No entries yet. Add Wi-Fi, breakfast, check-out, and policy answers.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="kbDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? 'Edit entry' : 'New entry' }}
                    </DialogTitle>
                </DialogHeader>

                <div class="space-y-3">
                    <div>
                        <Label for="kb-topic">Topic</Label>
                        <Input id="kb-topic" v-model="kbForm.topic" placeholder="Wi-Fi" />
                    </div>
                    <div>
                        <Label for="kb-question">Example question</Label>
                        <Input
                            id="kb-question"
                            v-model="kbForm.question"
                            placeholder="What is the Wi-Fi password?"
                        />
                    </div>
                    <div>
                        <Label for="kb-answer">Answer</Label>
                        <textarea
                            id="kb-answer"
                            v-model="kbForm.answer"
                            rows="4"
                            class="border-input bg-background text-foreground mt-1 w-full resize-none rounded-md border px-3 py-2 text-sm focus:outline-none"
                            placeholder="Connect to GUEST-WIFI with password…"
                        />
                    </div>
                    <div>
                        <Label for="kb-keywords">Keywords (comma separated)</Label>
                        <Input
                            id="kb-keywords"
                            v-model="kbForm.keywords"
                            placeholder="wifi, internet, password, network"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="kbForm.active" type="checkbox" />
                        Active
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="kbDialogOpen = false">
                        Cancel
                    </Button>
                    <Button @click="saveEntry">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
