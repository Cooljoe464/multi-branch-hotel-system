import { computed, ref } from 'vue';
import { en, type LocaleDict } from '@/locales/en';
import { fr } from '@/locales/fr';

export type LocaleCode = 'en' | 'fr';

const dictionaries: Record<LocaleCode, LocaleDict> = { en, fr };

export const locales: { code: LocaleCode; label: string }[] = [
    { code: 'en', label: 'English' },
    { code: 'fr', label: 'Français' },
];

const STORAGE_KEY = 'guest-locale';

function defaultLocale(): LocaleCode {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored === 'en' || stored === 'fr') return stored;
        if (window.navigator.language.toLowerCase().startsWith('fr')) return 'fr';
    } catch {
        // Private mode or SSR: fall through to English.
    }
    return 'en';
}

const current = ref<LocaleCode>(defaultLocale());

export const locale = computed(() => current.value);

export function setLocale(code: LocaleCode): void {
    current.value = code;
    try {
        window.localStorage.setItem(STORAGE_KEY, code);
    } catch {
        // Persistence is a nicety; translation still works.
    }
}

type LeafPaths<T, Prefix extends string = ''> = {
    [K in keyof T]: T[K] extends Record<string, unknown>
        ? LeafPaths<T[K], `${Prefix}${K & string}.`>
        : `${Prefix}${K & string}`;
}[keyof T];

export type LocaleKey = LeafPaths<LocaleDict>;

function lookup(dict: LocaleDict, key: string): string | null {
    let node: unknown = dict;
    for (const part of key.split('.')) {
        if (typeof node !== 'object' || node === null || !(part in node)) return null;
        node = (node as Record<string, unknown>)[part];
    }
    return typeof node === 'string' ? node : null;
}

function interpolate(template: string, params: Record<string, string | number>): string {
    let out = template;
    for (const [name, value] of Object.entries(params)) {
        out = out.split(`{${name}}`).join(String(value));
    }
    // `{s}` plural marker: kept only when the `count` (or `n`)
    // param exceeds 1; singular otherwise.
    const n = Number(params.count ?? params.n ?? 2);
    return out.split('{s}').join(n > 1 ? 's' : '');
}

/**
 * Translate a dotted key (`booking.search`), falling back to English
 * when French has no entry. `{params}` interpolate; `{n}…{s}` handles
 * the one plural marker used in these dictionaries.
 */
export function t(key: LocaleKey, params: Record<string, string | number> = {}): string {
    const template =
        lookup(dictionaries[current.value], key) ?? lookup(dictionaries.en, key) ?? key;
    return interpolate(template, params);
}
