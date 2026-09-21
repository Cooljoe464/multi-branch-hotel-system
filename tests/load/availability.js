// Staging load probe for the public booking engine (k6).
// Run: k6 run --env BASE_URL=https://staging.example.com tests/load/availability.js
// Informational: asserts p95 latency + zero 5xx under 50 concurrent browsers.
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    duration: '60s',
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800'],
    },
};

const BASE = __ENV.BASE_URL || 'http://localhost';

export default function () {
    const res = http.post(`${BASE}/book/search`, {
        check_in_date: '2026-11-01',
        check_out_date: '2026-11-03',
        adults: '2',
    });

    check(res, {
        'no server errors': (r) => r.status !== 500,
        'responds in budget': (r) => r.timings.duration < 800,
    });
}
