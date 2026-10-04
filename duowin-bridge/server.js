import express from 'express';
import { SerialPort } from 'serialport';

const app = express();
app.use(express.json());

const PORT = parseInt(process.env.BRIDGE_PORT || '3100', 10);
const SERIAL_PATH = process.env.SERIAL_PATH || 'COM3';
const SERIAL_BAUD = parseInt(process.env.SERIAL_BAUD || '9600', 10);

let port;

function connectSerial() {
    try {
        port = new SerialPort({ path: SERIAL_PATH, baudRate: SERIAL_BAUD });
        port.on('error', (err) => {
            console.error('Serial port error:', err.message);
        });
        port.on('open', () => {
            console.log(
                `Serial port ${SERIAL_PATH} opened at ${SERIAL_BAUD} baud`,
            );
        });
    } catch (err) {
        console.error('Failed to open serial port:', err.message);
    }
}

connectSerial();

app.get('/health', (_req, res) => {
    res.json({
        ok: true,
        serialOpen: port?.isOpen ?? false,
        port: SERIAL_PATH,
    });
});

app.post('/encode', (req, res) => {
    const { room_number, pin_code, valid_from, valid_until } = req.body;

    if (!room_number || !pin_code) {
        return res
            .status(400)
            .json({ error: 'room_number and pin_code are required' });
    }

    if (!port?.isOpen) {
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

    port.write(payload, (err) => {
        if (err) {
            return res.status(500).json({ error: err.message });
        }

        port.once('data', (data) => {
            const response = data.toString().trim();
            try {
                const parsed = JSON.parse(response);
                res.json({
                    card_id: parsed.card_id,
                    room_number,
                    success: true,
                });
            } catch {
                res.json({ card_id: response, room_number, success: true });
            }
        });
    });

    setTimeout(() => {
        res.status(504).json({ error: 'Serial timeout' });
    }, 10000);
});

app.post('/revoke', (req, res) => {
    const { card_id, room_number } = req.body;

    if (!card_id) {
        return res.status(400).json({ error: 'card_id is required' });
    }

    if (!port?.isOpen) {
        return res.status(503).json({ error: 'Serial port not open' });
    }

    const payload =
        JSON.stringify({
            command: 'REVOKE',
            card_id,
            room_number,
        }) + '\n';

    port.write(payload, (err) => {
        if (err) {
            return res.status(500).json({ error: err.message });
        }

        res.json({ success: true, card_id });
    });
});

app.post('/extend', (req, res) => {
    const { card_id, valid_until } = req.body;

    if (!card_id || !valid_until) {
        return res
            .status(400)
            .json({ error: 'card_id and valid_until are required' });
    }

    if (!port?.isOpen) {
        return res.status(503).json({ error: 'Serial port not open' });
    }

    const payload =
        JSON.stringify({
            command: 'EXTEND',
            card_id,
            valid_until,
        }) + '\n';

    port.write(payload, (err) => {
        if (err) {
            return res.status(500).json({ error: err.message });
        }

        res.json({ success: true, card_id });
    });
});

app.listen(PORT, () => {
    console.log(`Duowin bridge running on port ${PORT}`);
});
