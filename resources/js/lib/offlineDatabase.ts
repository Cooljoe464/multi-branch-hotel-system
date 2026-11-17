import { Database, Model } from '@nozbe/watermelondb';
import LokiJSAdapter from '@nozbe/watermelondb/adapters/lokijs';
import { appSchema, tableSchema } from '@nozbe/watermelondb/Schema';
import { addColumns, schemaMigrations } from '@nozbe/watermelondb/Schema/migrations';

const databaseName = 'hospitality-os-offline-v2';

const offlineSchema = appSchema({
    version: 2,
    tables: [
        tableSchema({
            name: 'offline_commands',
            columns: [
                { name: 'command_uuid', type: 'string', isIndexed: true },
                { name: 'command_type', type: 'string' },
                { name: 'payload_json', type: 'string' },
                { name: 'status', type: 'string', isIndexed: true },
                { name: 'created_at', type: 'number', isIndexed: true },
                { name: 'outage_id', type: 'string', isIndexed: true },
                { name: 'reason', type: 'string', isOptional: true },
            ],
        }),
        tableSchema({
            name: 'offline_device',
            columns: [
                { name: 'key', type: 'string', isIndexed: true },
                { name: 'value', type: 'string' },
            ],
        }),
    ],
});

const offlineMigrations = schemaMigrations({
    migrations: [
        {
            toVersion: 2,
            steps: [
                addColumns({
                    table: 'offline_commands',
                    columns: [
                        { name: 'created_at', type: 'number', isIndexed: true },
                        { name: 'outage_id', type: 'string', isIndexed: true },
                        { name: 'reason', type: 'string', isOptional: true },
                    ],
                }),
            ],
        },
    ],
});

export class OfflineCommandRecord extends Model {
    static table = 'offline_commands';
}

export class OfflineDeviceRecord extends Model {
    static table = 'offline_device';
}

let databasePromise: Promise<Database> | undefined;

export function getOfflineDatabase(): Promise<Database> {
    if (!databasePromise) {
        const adapter = new LokiJSAdapter({
            dbName: databaseName,
            schema: offlineSchema,
            migrations: offlineMigrations,
            useWebWorker: false,
            useIncrementalIndexedDB: true,
            onQuotaExceededError: () => {
                window.dispatchEvent(new Event('hospitality:offline-storage-full'));
            },
            onSetUpError: () => {
                window.dispatchEvent(new Event('hospitality:offline-storage-error'));
            },
            extraIncrementalIDBOptions: {
                onversionchange: () => {
                    window.dispatchEvent(new Event('hospitality:offline-database-reset'));
                },
            },
        });

        databasePromise = Promise.resolve(
            new Database({
                adapter,
                modelClasses: [OfflineCommandRecord, OfflineDeviceRecord],
            }),
        );
    }

    return databasePromise;
}

export function offlineColumn<T extends string | number>(record: Model, column: string): T {
    return record._getRaw(column) as T;
}

export const legacyOfflineDatabaseName = 'hospitality-os-offline';
