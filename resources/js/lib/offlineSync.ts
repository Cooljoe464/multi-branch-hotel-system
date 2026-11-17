import { Q } from '@nozbe/watermelondb';
import type Model from '@nozbe/watermelondb/Model';
import { toast } from 'vue-sonner';
import {
    getOfflineDatabase,
    legacyOfflineDatabaseName,
    OfflineCommandRecord,
    OfflineDeviceRecord,
    offlineColumn,
} from '@/lib/offlineDatabase';

export type OfflineCommandStatus =
    | 'pending'
    | 'applied'
    | 'conflict'
    | 'rejected';

export type OfflineCommand = {
    command_uuid: string;
    command_type: 'check_in.provisional';
    payload: Record<string, unknown>;
    status?: OfflineCommandStatus;
};

export type LocalProvisionalStatus = {
    status: OfflineCommandStatus;
    reason: string | null;
};

type StoredOfflineCommand = OfflineCommand & {
    status: OfflineCommandStatus;
    outage_id: string;
    created_at: number;
    reason: string | null;
};

type PurgeReason = 'logout' | 'tenant_switch' | 'remote_revoke' | 'manual';

const offlineBudgetBytes = 50 * 1024 * 1024;
const maxProvisionalCheckInsPerOutage = 20;
const syncBatchSize = 100;
const purgeChannelName = 'hospitality-offline-purge';

let purgeChannel: BroadcastChannel | undefined;

function rawValue<T extends string | number>(record: Model, column: string): T {
    return offlineColumn<T>(record, column);
}

function commandValue(record: OfflineCommandRecord): StoredOfflineCommand {
    return {
        command_uuid: rawValue(record, 'command_uuid'),
        command_type: rawValue(record, 'command_type') as OfflineCommand['command_type'],
        payload: JSON.parse(rawValue(record, 'payload_json')) as Record<string, unknown>,
        status: rawValue(record, 'status') as OfflineCommandStatus,
        outage_id: rawValue(record, 'outage_id'),
        created_at: rawValue(record, 'created_at'),
        reason: record._getRaw('reason') as string | null,
    };
}

async function setting(key: string): Promise<string | null> {
    const database = await getOfflineDatabase();
    const row = await database
        .get<OfflineDeviceRecord>('offline_device')
        .query(Q.where('key', key))
        .fetch();

    return row.length > 0 ? rawValue<string>(row[0], 'value') : null;
}

async function putSetting(key: string, value: string | null): Promise<void> {
    const database = await getOfflineDatabase();
    const collection = database.get<OfflineDeviceRecord>('offline_device');
    const rows = await collection.query(Q.where('key', key)).fetch();

    await database.write(async () => {
        if (value === null) {
            for (const row of rows) {
                await row.destroyPermanently();
            }

            return;
        }

        if (rows.length > 0) {
            await rows[0].update((record) => {
                record._setRaw('value', value);
            });

            return;
        }

        await collection.create((record) => {
            record._setRaw('key', key);
            record._setRaw('value', value);
        });
    });
}

async function deviceId(): Promise<string> {
    const existing = await setting('device_id');

    if (existing) {
        return existing;
    }

    const generated = crypto.randomUUID();
    await putSetting('device_id', generated);

    return generated;
}

async function activeOutageId(): Promise<string> {
    const existing = await setting('outage_id');

    if (existing) {
        return existing;
    }

    const generated = crypto.randomUUID();
    await putSetting('outage_id', generated);
    await putSetting('outage_started_at', String(Date.now()));

    return generated;
}

async function pendingCommandRecords(): Promise<OfflineCommandRecord[]> {
    const database = await getOfflineDatabase();

    return database
        .get<OfflineCommandRecord>('offline_commands')
        .query(Q.where('status', 'pending'), Q.sortBy('created_at', Q.asc))
        .fetch();
}

export async function queueOfflineCommand(
    command: Omit<OfflineCommand, 'command_uuid' | 'status'>,
): Promise<OfflineCommand> {
    const database = await getOfflineDatabase();
    const outageId = await activeOutageId();
    const serializedPayload = JSON.stringify(command.payload);
    const bytesRequired = new TextEncoder().encode(serializedPayload).byteLength;
    const stored = await database
        .get<OfflineCommandRecord>('offline_commands')
        .query(Q.sortBy('created_at', Q.asc))
        .fetch();
    const bytesUsed = stored.reduce(
        (total, record) =>
            total +
            new TextEncoder().encode(rawValue<string>(record, 'payload_json'))
                .byteLength,
        0,
    );

    if (bytesUsed + bytesRequired > offlineBudgetBytes) {
        throw new Error('Offline storage is full. Sync before adding more work.');
    }

    if (command.command_type === 'check_in.provisional') {
        const outageCommands = await database
            .get<OfflineCommandRecord>('offline_commands')
            .query(Q.where('outage_id', outageId))
            .fetch();
        const checkIns = outageCommands.filter(
            (record) => rawValue(record, 'command_type') === 'check_in.provisional',
        ).length;

        if (checkIns >= maxProvisionalCheckInsPerOutage) {
            throw new Error(
                `This device has reached the ${maxProvisionalCheckInsPerOutage} provisional check-in limit for this outage.`,
            );
        }
    }

    const commandUuid = crypto.randomUUID();
    await database.write(async () => {
        await database
            .get<OfflineCommandRecord>('offline_commands')
            .create((record) => {
                record._setRaw('command_uuid', commandUuid);
                record._setRaw('command_type', command.command_type);
                record._setRaw('payload_json', serializedPayload);
                record._setRaw('status', 'pending');
                record._setRaw('outage_id', outageId);
                record._setRaw('created_at', Date.now());
                record._setRaw('reason', null);
            });
    });

    window.dispatchEvent(new Event('hospitality:offline-command-updated'));

    return { ...command, command_uuid: commandUuid, status: 'pending' };
}

export async function provisionalStatusForReservation(
    reservationId: number,
): Promise<LocalProvisionalStatus | null> {
    const database = await getOfflineDatabase();
    const records = await database
        .get<OfflineCommandRecord>('offline_commands')
        .query(
            Q.where('command_type', 'check_in.provisional'),
            Q.sortBy('created_at', Q.desc),
        )
        .fetch();

    for (const record of records) {
        let payload: Record<string, unknown>;
        try {
            payload = JSON.parse(rawValue<string>(record, 'payload_json')) as Record<string, unknown>;
        } catch {
            continue;
        }

        if (payload.reservation_id === reservationId) {
            return {
                status: rawValue(record, 'status') as OfflineCommandStatus,
                reason: record._getRaw('reason') as string | null,
            };
        }
    }

    return null;
}

export async function pendingCommands(): Promise<OfflineCommand[]> {
    return (await pendingCommandRecords()).map((record) => {
        const command = commandValue(record);

        return {
            command_uuid: command.command_uuid,
            command_type: command.command_type,
            payload: command.payload,
            status: command.status,
        };
    });
}

export async function syncOfflineCommands(
    csrfToken: string,
    accessToken?: string,
): Promise<{
    data: Array<{
        command_uuid: string;
        status: OfflineCommandStatus;
        reason?: string | null;
    }>;
}> {
    const allResults: Array<{
        command_uuid: string;
        status: OfflineCommandStatus;
        reason?: string | null;
    }> = [];

    while (true) {
        const commands = (await pendingCommands()).slice(0, syncBatchSize);
        if (commands.length === 0) {
            break;
        }

        const headers: HeadersInit = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Idempotency-Key': crypto.randomUUID(),
        };
        if (accessToken) {
            headers.Authorization = `Bearer ${accessToken}`;
        }

        const response = await fetch('/api/v1/sync/push', {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify({
                device_id: await deviceId(),
                commands,
            }),
        });

        if (!response.ok) {
            if (response.status === 401 || response.status === 403) {
                await purgeOfflineData('remote_revoke');
            }

            throw new Error(`Offline sync failed with status ${response.status}.`);
        }

        const result = (await response.json()) as {
            data?: Array<{
                command_uuid: string;
                status: OfflineCommandStatus;
                reason?: string | null;
            }>;
        };
        const results = result.data ?? [];
        const returnedCommandUuids = new Set(
            results.map((command) => command.command_uuid),
        );
        if (
            results.length === 0 ||
            returnedCommandUuids.size !== results.length ||
            commands.some((command) => !returnedCommandUuids.has(command.command_uuid))
        ) {
            throw new Error('Offline sync returned an incomplete command result set.');
        }
        await updateStatuses(results);
        allResults.push(...results);
    }

    if ((await pendingCommandRecords()).length === 0) {
        await putSetting('outage_id', null);
        await putSetting('outage_started_at', null);
    }

    return { data: allResults };
}

async function updateStatuses(
    results: Array<{
        command_uuid: string;
        status: OfflineCommandStatus;
        reason?: string | null;
    }>,
): Promise<void> {
    const database = await getOfflineDatabase();
    const collection = database.get<OfflineCommandRecord>('offline_commands');
    const changes = await Promise.all(
        results.map(async (result) => {
            const rows = await collection
                .query(Q.where('command_uuid', result.command_uuid))
                .fetch();

            return rows[0];
        }),
    );

    await database.write(async () => {
        for (const [index, record] of changes.entries()) {
            if (record) {
                await record.update((row) => {
                    row._setRaw('status', results[index].status);
                    row._setRaw('reason', results[index].reason ?? null);
                });
            }
        }
    });

    window.dispatchEvent(new Event('hospitality:offline-command-updated'));
}

function clearLegacyOfflineDatabase(): Promise<void> {
    return new Promise((resolve) => {
        const request = indexedDB.deleteDatabase(legacyOfflineDatabaseName);
        request.onsuccess = () => resolve();
        request.onerror = () => resolve();
        request.onblocked = () => resolve();
    });
}

export async function purgeOfflineData(
    reason: PurgeReason = 'manual',
    broadcast = true,
): Promise<number> {
    navigator.serviceWorker?.controller?.postMessage({ type: 'purge-offline-data' });
    if ('caches' in window) {
        const cacheNames = await caches.keys();
        await Promise.all(
            cacheNames
                .filter((name) => name.startsWith('hospitality-os-shell-'))
                .map((name) => caches.delete(name)),
        );
    }

    let unsyncedCount = 0;
    for (let index = localStorage.length - 1; index >= 0; index -= 1) {
        const key = localStorage.key(index);
        if (key?.startsWith('pos_offline_')) {
            try {
                const queued = JSON.parse(localStorage.getItem(key) ?? '[]') as unknown[];
                unsyncedCount += queued.length;
            } catch {
                unsyncedCount += 1;
            }
            localStorage.removeItem(key);
        }
    }

    try {
        const database = await getOfflineDatabase();
        const records = await database
            .get<OfflineCommandRecord>('offline_commands')
            .query(Q.where('status', Q.oneOf(['pending', 'conflict'])))
            .fetch();
        unsyncedCount += records.length;
        await database.unsafeResetDatabase();
    } finally {
        await clearLegacyOfflineDatabase();
    }

    window.dispatchEvent(
        new CustomEvent('hospitality:offline-purged', {
            detail: { reason, unsyncedCount },
        }),
    );

    if (broadcast && typeof BroadcastChannel !== 'undefined') {
        if (!purgeChannel) {
            purgeChannel = new BroadcastChannel(purgeChannelName);
        }
        purgeChannel.postMessage({ type: 'purge', reason });
    }

    return unsyncedCount;
}

export function registerOfflinePurgeHooks(): void {
    if (typeof BroadcastChannel === 'undefined' || purgeChannel) {
        return;
    }

    purgeChannel = new BroadcastChannel(purgeChannelName);
    purgeChannel.onmessage = (event: MessageEvent<{ type?: string; reason?: PurgeReason }>) => {
        if (event.data?.type === 'purge') {
            void purgeOfflineData(event.data.reason ?? 'manual', false);
        }
    };
}

export async function registerOfflineWorker(): Promise<ServiceWorkerRegistration | undefined> {
    if (!('serviceWorker' in navigator)) {
        return undefined;
    }

    return navigator.serviceWorker.register('/sw.js', { scope: '/' });
}

export function initOfflineSync(): void {
    window.addEventListener('online', syncOfflineQueue);
    window.setInterval(syncOfflineQueue, 30_000);
    window.addEventListener('hospitality:offline-purged', (event) => {
        const detail = (event as CustomEvent<{ unsyncedCount: number }>).detail;
        if (detail.unsyncedCount > 0) {
            toast.warning(
                `${detail.unsyncedCount} unsynced offline action${detail.unsyncedCount === 1 ? ' was' : 's were'} cleared.`,
            );
        }
    });
    window.addEventListener('hospitality:offline-storage-full', () => {
        toast.warning('Offline storage is full. Sync before adding more work.');
    });
    window.addEventListener('hospitality:offline-storage-error', () => {
        toast.error('Offline storage could not be opened. Reload before checking in offline.');
    });
    window.addEventListener('hospitality:offline-database-reset', () => {
        window.location.reload();
    });
}

async function syncOfflineQueue(): Promise<void> {
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    if (!csrfToken) {
        return;
    }

    try {
        const result = await withSyncLeadership(() =>
            syncOfflineCommands(csrfToken),
        );
        const rejectedReasons = result?.data
            .filter((command) => command.status === 'rejected')
            .map((command) => command.reason)
            .filter((reason): reason is string => Boolean(reason));
        if (rejectedReasons?.length) {
            toast.warning(rejectedReasons.join(' '));
        }
    } catch {
        // Keep queued work for the next network or scheduled sync attempt.
    }
}

export async function withSyncLeadership<T>(
    work: () => Promise<T>,
): Promise<T | undefined> {
    if (!('locks' in navigator)) {
        return work();
    }

    return navigator.locks.request(
        'hospitality-os-offline-sync',
        { ifAvailable: true },
        async (lock) => (lock ? work() : undefined),
    );
}
