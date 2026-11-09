const API_BASE = 'backend';
let csrfToken = sessionStorage.getItem('sms_csrf') || '';

async function api(path, { method = 'GET', body } = {}) {
  const res = await fetch(`${API_BASE}/${path}`, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json();
  if (!json.success) throw Object.assign(new Error(json.error), { status: res.status, details: json.details });
  return json.data;
}

async function login(username, password) {
  const data = await api('auth.php?action=login', { method: 'POST', body: { username, password } });
  csrfToken = data.csrf_token;
  sessionStorage.setItem('sms_csrf', csrfToken);
  return data.user;
}
