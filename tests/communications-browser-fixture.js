/* Local-only UI fixture. No WordPress authentication or school data is used. */
'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const actors = [{actor_type: 'employee', actor_id: 'E-42', actor_key: 'employee:E-42', display_name: 'أحمد محمد'}, {actor_type: 'family', actor_id: 'test-family', actor_key: 'family:test-family', display_name: 'أسرة أحمد وسارة'}];
const receipts = [];
const chatFixture = require('./communications-chat-browser-fixture');
const suiteFixture = require('./communications-suite-browser-fixture');
http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1:13388');
    if (url.pathname === '/') {
        const config = {root: '/api/', nonce: 'fixture', actors, userId: 1, sessionScope: 'isolated-fixture', notifications: true, pollSeconds: 10, chat: true, suite: true, timezone: "Asia/Amman", messageMaxChars: 5000, editMinutes: 15};
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OLAMA Communications — isolated UI test</title><link rel="stylesheet" href="/assets/communications-app.css"><body><main data-olama-communications class="olama-communications"></main><script>window.OlamaCommunications=' + JSON.stringify(config) + '</script><script src="/assets/communications-app.js"></script><script src="/assets/communications-chat.js"></script><script src="/assets/communications-suite.js"></script></body></html>'); return;
    }
    if (['/assets/communications-app.js', '/assets/communications-chat.js', '/assets/communications-suite.js', '/assets/communications-app.css'].includes(url.pathname)) {
        res.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'); res.end(fs.readFileSync(path.join(root, url.pathname))); return;
    }
    let raw = ''; for await (const chunk of req) raw += chunk;
    const data = raw ? JSON.parse(raw) : {};
    const actor = actors.find(item => item.actor_key === req.headers['x-olama-actor']);
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    if (url.pathname === '/receipts-log') { res.end(JSON.stringify(receipts)); return; }
    if (!actor && url.pathname !== '/receipts-log') { res.statusCode = 403; res.end('{}'); return; }
    const employee = actor && actor.actor_type === 'employee';
    const title = employee ? 'اجتماع الهيئة التدريسية — خاص بالموظفين' : 'اليوم المفتوح لأولياء الأمور';
    const notice = {id: employee ? 1 : 2, rendered_title: title, rendered_body: 'يسر إدارة المدرسة دعوتكم للمشاركة.\nالموعد: الخميس الساعة العاشرة صباحاً.\nملف للاختبار: Schedule-2026.pdf\n<script>window.fixtureInjected=true</script>', purpose: 'acknowledgement', sent_at_utc: '2026-09-08 09:00:00', campaign_status: 'published', acknowledged_at_utc: receipts.some(r => r.kind === 'acknowledge' && r.actor === actor.actor_key) ? '2026-09-08 10:00:00' : null};
    let result = [];
    if (url.pathname === '/api/me') result = {actor, available_actors: actors, can_manage: employee, chat: true, can_moderate: employee, can_manage_inboxes: employee, can_audit: employee};
    else if (url.pathname === '/api/notices') result = [notice];
    else if (url.pathname.startsWith('/api/notices/')) result = notice;
    else if (url.pathname === '/api/notifications') result = Number(url.searchParams.get('after')) > 0 ? [] : [{id: notice.id, delivery_id: notice.id, rendered_title: title, created_at_utc: notice.sent_at_utc}];
    else if (url.pathname === '/api/counts') result = {notices: 1, notifications: 1};
    else if (url.pathname === '/api/receipts') { receipts.push({...data, actor: actor.actor_key}); result = {ok: true}; }
    else if (url.pathname.startsWith('/api/suite/')) result = suiteFixture(url, actor, data, req.method);
    else if (url.pathname.startsWith('/api/chat/')) result = chatFixture(url, actor, data, req.method, receipts);
    else if (url.pathname === '/receipts-log') result = receipts;
    res.end(JSON.stringify(result));
}).listen(13388, '127.0.0.1', () => console.log('Isolated browser fixture: http://127.0.0.1:13388'));
