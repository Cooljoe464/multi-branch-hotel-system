import express from 'express';
import { SerialPort } from 'serialport';
import crypto from 'node:crypto';

const app = express();
app.use(express.json());

const PORT = parseInt(process.env.BRIDGE_PORT || '3100', 10);
const HOST = process.env.BRIDGE_HOST || '127.0.0.1';
const SERIAL_PATH = process.env.SERIAL_PATH || 'COM3';
const SERIAL_BAUD = parseInt(process.env.SERIAL_BAUD || '9600', 10);
const BRIDGE_TOKEN = process.env.BRIDGE_TOKEN || '';

if (!BRIDGE_TOKEN && process.env.NODE_ENV === 'production') {
    console.error('FATAL: BRIDGE_TOKEN environment variable must be set.');
    process.exit(1);
}

const sha256 = (value) => crypto.createHash('sha256').update(String(value)).digest('hex');
const timingSafeEqual = (a, b) =>
    crypto.timingSafeEqual(sha256(a), sha256(b));

const requireBearerToken = (req, res, next) => {
    const header = req.headers.authorization ?? '';
    const [scheme, token] = header.split(' ');

    if (scheme !== 'Bearer' || !token || !timingSafeEqual(token, BRIDGE_TOKEN)) {
        return res.status(401).json({ error: 'unauthorized' });
    }

    return next();
};

app.use('/encode', requireBearerToken);
app.use('/revoke', requireBearerToken);
app.use('/extend', requireBearerToken);

let port;
let serialOpen = false;
let reconnectTimer = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_DELAY_MS = 30000;

const scheduleReconnect = () => {
    if (reconnectTimer) {
        return;
    }

    reconnectAttempts += 1;
    const delay = Math.min(1000 * (2 ** Math.min(reconnectAttempts, 5)), MAX_RECONNECT_DELAY_MS);

    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        connectSerial();
    }, delay);
};

function connectSerial() {
    try {
        port = new SerialPort({ path: SERIAL_PATH, baudRate: SERIAL_BAUD });
        port.on('error', (err) => {
            console.error('Serial port error:', err.message);
            scheduleReconnect();
        });
        port.on('close', () => {
            serialOpen = false;
            scheduleReconnect();
        });
        port.on('open', () => {
            serialOpen = true;
            reconnectAttempts = 0;
            console.log(`Serial port ${SERIAL_PATH} opened at ${SERIAL_BAUD} baud`);
        });
    } catch (err) {
        console.error('Failed to open serial port:', err.message);
        scheduleReconnect();
    }
}

connectSerial();

app.get('/health', (_req, res) => {
    res.json({
        ok: true,
        serialOpen,
        port: SERIAL_PATH,
    });
});

const withSingleFlight = (handler) => {
    const previous = requestChain;
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    requestChain = gate;

    return gate
        .then(handler)
        .catch(() => {})
        .finally(() => release());
};

let requestChain = Promise.resolve();

const writeCommand = (payload) =>
    new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            cleanup();
            reject(new Error('Serial timeout'));
        }, 10000);

        const onData = (data) => {
            cleanup();
            resolve(data.toString().trim());
        };

        const onError = (err) => {
            cleanup();
            reject(err);
        };

        const cleanup = () => {
            clearTimeout(timer);
            port.off('data', onData);
            port.off('error', onError);
        };

        port.on('data', onData);
        port.on('error', onError);
        port.write(payload, (err) => {
            if (err) {
                cleanup();
                reject(err);
            }
        });
    });

app.post('/encode', async (req, res) => {
    const { room_number, pin_code, valid_from, valid_until } = req.body ?? {};

    if (!room_number || !pin_code) {
        return res.status(400).json({ error: 'room_number and pin_code are required' });
    }

    if (!serialOpen) {
        return res.status(503).json({ error: 'Serial port not open' });
    }

    const payload =
        JSON.stringify({
            command: 'ENCODE',
            room_number,
            pin_code,
            valid_from: valid_from || new Date().toISOString(),
            valid_until:
                valid_until ||
                new Date(Date.now() + 7 * 86400000).toISOString(),
        }) + '\n';

    try {
        const response = await withSingleFlight(() =>
            writeCommand(payload).then((raw) => {
                try {
                    const parsed = JSON.parse(raw);
                    return { card_id: parsed.card_id, room_number, success: true };
                } catch {
                    return { card_id: raw, room_number, success: true };
                }
            }),
        );

        if (res.headersSent) {
            return;
        }

        return res.status(200).json(response);
    } catch (err) {
        if (res.headersSent) {
            return;
        }

        return res.status(504).json({ error: 'Serial timeout' });
    }
});

app.post('/revoke', async (req, res) => {
    const { card_id, room_number } = req.body ?? {};

    if (!card_id) {
        return res.status(400).json({ error: 'card_id is required' });
    }

    if (!serialOpen) {
        return res.status(503).json({ error: 'Serial port not open' });
    }

    const payload =
        JSON.stringify({
            command: 'REVOKE',
            card_id,
            room_number,
        }) + '\n';

    try {
        const response = await withSingleFlight(() =>
            writeCommand(payload).then(() => ({ success: true, card_id })),
        );

        if (res.headersSent) {
            return;
        }

        return res.status(200).json(response);
    } catch (err) {
        if (res.headersSent) {
            return;
        }

        return res.status(504).json({ error: 'Serial timeout' });
    }
});

app.post('/extend', async (req, res) => {
    const { card_id, valid_until } = req.body ?? {};

    if (!card_id || !valid_until) {
        return res.status(400).json({ error: 'card_id and valid_until are required' });
    }

    if (!serialOpen) {
        return res.status(503).json({ error: 'Serial port not open' });
    }

    const payload =
        JSON.stringify({
            command: 'EXTEND',
            card_id,
            valid_until,
        }) + '\n';

    try {
        const response = await withSingleFlight(() =>
            writeCommand(payload).then(() => ({ success: true, card_id })),
        );

        if (res.headersSent) {
            return;
        }

        return res.status(200).json(response);
    } catch (err) {
        if (res.headersSent) {
            return;
        }

        return res.status(504).json({ error: 'Serial timeout' });
    }
});

app.listen(PORT, HOST, () => {
    console.log(`Duowin bridge running on http://${HOST}:${PORT}`);
});
