import { check, sleep } from 'k6'
import http from 'k6/http'

export const options = {
  vus: 3,
  duration: '5s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
    checks: ['rate>=0.99'],
  },
}

export default function () {
  const response = http.get('http://127.0.0.1:8001/', { timeout: '2s' })
  check(response, {
    'welcome status is 200': (result) => result.status === 200,
    'welcome page has the application title': (result) =>
      typeof result.body === 'string' && result.body.includes('<title>ExpedFlow</title>'),
  })
  sleep(0.1)
}
