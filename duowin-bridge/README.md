# Local Hardware Door Lock & Encoder Bridge

This local Node.js daemon bridges HospitalityOS with physical serial (RS-232 / COM port / USB) card encoders and hotel door lock hardware.

## Features
- **Bearer Token Authentication:** Every `/encode`, `/revoke`, and `/extend` request requires an `Authorization: Bearer <BRIDGE_TOKEN>` header verified with constant-time equality.
- **Local Host Binding:** Binds to `127.0.0.1` by default to prevent unauthorized network access.
- **Single Flight Request Queue:** Prevents concurrent COM port race conditions and response cross-wiring.
- **Auto-Reconnect:** Exponential reconnect backoff when serial devices are unplugged or reset.

## Configuration (.env)
```ini
BRIDGE_PORT=3100
BRIDGE_HOST=127.0.0.1
SERIAL_PATH=COM3
SERIAL_BAUD=9600
BRIDGE_TOKEN=your-secure-random-token-here
```

## Running
```bash
npm install
node server.js
```

Or using Docker:
```bash
docker build -t duowin-bridge .
docker run -d --name duowin-bridge -p 3100:3100 --device=/dev/ttyUSB0 duowin-bridge
```
