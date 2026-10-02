/**
 * A stand-in for the OpenAI Responses API and for an OpenAI-compatible
 * Chat Completions server (Ollama, vLLM…), used by the Playwright tests.
 * Ada talks to it exactly as to the real API (the provider's base URL points
 * here), so no test-only code exists in the application.
 *
 *   POST /v1/responses/input_tokens  → token count
 *   POST /v1/responses               → SSE stream (slow when the prompt contains "long";
 *                                       names the assistant when the instructions
 *                                       contain "Talimat: <name>")
 *   POST /v1/chat/completions        → SSE stream, data-only chunks ending in [DONE];
 *                                       refuses a request with credentials (keyless server)
 *   GET  /v1/models                  → connection check
 *
 * It also plays an OpenID Connect provider (issuer http://127.0.0.1:<port>/oidc)
 * that signs in one user without asking:
 *
 *   GET  /oidc/.well-known/openid-configuration, /oidc/keys
 *   GET  /oidc/authorize → redirects back with a code at once
 *   POST /oidc/token     → RS256 ID token (checks the PKCE verifier)
 */
import { createHash, generateKeyPairSync, randomUUID, sign } from 'node:crypto';
import { createServer } from 'node:http';

const port = Number(process.env.MOCK_PROVIDER_PORT ?? 8765);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const ANSWER = [
    '## Merhaba!\n\n',
    'Bu yanıt **sahte sağlayıcıdan** geliyor.\n\n',
    '- Birinci madde\n',
    '- İkinci madde\n\n',
    '```php\n',
    "echo 'Ada';\n",
    '```\n',
];

const COMPATIBLE_ANSWER = ['Yerel modelden ', '**merhaba**', '!'];

function readBody(request) {
    return new Promise((resolve) => {
        let body = '';
        request.on('data', (chunk) => (body += chunk));
        request.on('end', () => resolve(body ? JSON.parse(body) : {}));
    });
}

function event(response, name, data) {
    response.write(
        `event: ${name}\ndata: ${JSON.stringify({ type: name, ...data })}\n\n`,
    );
}

const issuer = `http://127.0.0.1:${port}/oidc`;
const { privateKey, publicKey } = generateKeyPairSync('rsa', {
    modulusLength: 2048,
});
// A new key ID per start, as a provider rotating its key would use: Ada
// may still have the previous run's key set cached.
const kid = randomUUID();
const jwk = { ...publicKey.export({ format: 'jwk' }), kid, use: 'sig' };
const oidcCodes = new Map();

const base64Url = (value) => Buffer.from(value).toString('base64url');

function idToken(claims) {
    const input = `${base64Url(JSON.stringify({ alg: 'RS256', typ: 'JWT', kid }))}.${base64Url(JSON.stringify(claims))}`;

    return `${input}.${sign('sha256', Buffer.from(input), privateKey).toString('base64url')}`;
}

function json(response, status, body) {
    response.writeHead(status, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(body));
}

function readForm(request) {
    return new Promise((resolve) => {
        let body = '';
        request.on('data', (chunk) => (body += chunk));
        request.on('end', () => resolve(new URLSearchParams(body)));
    });
}

async function oidc(request, response, url) {
    const path = url.pathname.slice('/oidc'.length);

    if (path === '/.well-known/openid-configuration') {
        return json(response, 200, {
            issuer,
            authorization_endpoint: `${issuer}/authorize`,
            token_endpoint: `${issuer}/token`,
            jwks_uri: `${issuer}/keys`,
            token_endpoint_auth_methods_supported: ['client_secret_basic'],
        });
    }

    if (path === '/keys') {
        return json(response, 200, { keys: [jwk] });
    }

    if (path === '/authorize') {
        const code = randomUUID();
        oidcCodes.set(code, {
            nonce: url.searchParams.get('nonce'),
            challenge: url.searchParams.get('code_challenge'),
            clientId: url.searchParams.get('client_id'),
        });
        const back = new URL(url.searchParams.get('redirect_uri'));
        back.searchParams.set('code', code);
        back.searchParams.set('state', url.searchParams.get('state'));
        response.writeHead(302, { Location: back.toString() });

        return response.end();
    }

    if (path === '/token' && request.method === 'POST') {
        const form = await readForm(request);
        const pending = oidcCodes.get(form.get('code'));
        oidcCodes.delete(form.get('code'));
        const verifier = form.get('code_verifier') ?? '';
        const challenge = createHash('sha256')
            .update(verifier)
            .digest('base64url');

        if (
            !pending ||
            challenge !== pending.challenge ||
            !request.headers.authorization?.startsWith('Basic ')
        ) {
            return json(response, 400, { error: 'invalid_grant' });
        }

        const now = Math.floor(Date.now() / 1000);

        return json(response, 200, {
            access_token: 'e2e',
            token_type: 'Bearer',
            id_token: idToken({
                iss: issuer,
                aud: pending.clientId,
                sub: 'oidc-e2e-user',
                iat: now,
                exp: now + 300,
                nonce: pending.nonce,
                name: 'Zeynep OIDC',
                email: 'zeynep.oidc@example.edu',
                email_verified: true,
            }),
        });
    }

    return json(response, 404, { error: 'not_found' });
}

const server = createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', `http://localhost:${port}`);

    if (url.pathname.startsWith('/oidc/')) {
        return oidc(request, response, url);
    }

    if (request.method === 'GET' && url.pathname === '/v1/models') {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ data: [] }));

        return;
    }

    if (
        request.method === 'POST' &&
        url.pathname === '/v1/responses/input_tokens'
    ) {
        await readBody(request);
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ input_tokens: 42 }));

        return;
    }

    if (request.method === 'POST' && url.pathname === '/v1/responses') {
        const body = await readBody(request);
        const last = body.input?.at(-1)?.content ?? '';
        // With attachments the content is a list of input_text/input_image parts.
        const parts = Array.isArray(last)
            ? last
            : [{ type: 'input_text', text: last }];
        const text = parts
            .filter((part) => part.type === 'input_text')
            .map((part) => part.text)
            .join('');
        const files = parts.filter(
            (part) =>
                part.type === 'input_file' &&
                String(part.file_data).startsWith(
                    'data:application/pdf;base64,',
                ),
        ).length;
        const images = parts.filter(
            (part) =>
                part.type === 'input_image' &&
                String(part.image_url).startsWith('data:image/'),
        ).length;
        const long = text.includes('long');
        // Assistants: the instructions arrive as "instructions" (system prompt).
        const persona = /Talimat: (\S+)/.exec(body.instructions ?? '')?.[1];
        const documentCode = /Belge kodu: (\S+)/.exec(
            body.instructions ?? '',
        )?.[1];
        const chunks = persona
            ? [
                  `Asistan ${persona} burada. `,
                  ...(documentCode ? [`Belge ${documentCode} okundu. `] : []),
                  ...ANSWER,
              ]
            : long
              ? Array.from({ length: 80 }, (_, i) => `Kelime ${i + 1}. `)
              : files > 0
                ? [`PDF alındı: ${files}. `, ...ANSWER]
                : images > 0
                  ? [`Görsel sayısı: ${images}. `, ...ANSWER]
                  : text.includes('```')
                    ? ['Dosyayı okudum. ', ...ANSWER]
                    : ANSWER;

        let closed = false;
        request.on('close', () => (closed = true));

        response.writeHead(200, {
            'Content-Type': 'text/event-stream',
            'x-request-id': 'req_mock',
        });
        event(response, 'response.created', {
            response: { id: 'resp_mock', status: 'in_progress' },
        });

        let output = 0;

        for (const delta of chunks) {
            if (closed) {
                return;
            }

            await sleep(long ? 150 : 40);
            event(response, 'response.output_text.delta', { delta });
            output += 3;
        }

        event(response, 'response.completed', {
            response: {
                id: 'resp_mock',
                status: 'completed',
                usage: {
                    input_tokens: 42,
                    input_tokens_details: { cached_tokens: 0 },
                    output_tokens: output,
                    output_tokens_details: { reasoning_tokens: 0 },
                },
            },
        });
        response.end();

        return;
    }

    if (request.method === 'POST' && url.pathname === '/v1/chat/completions') {
        const body = await readBody(request);

        // The e2e provider is keyless: a key here would be a bug in Ada.
        if (
            request.headers.authorization ||
            !body.stream_options?.include_usage
        ) {
            response.writeHead(400, { 'Content-Type': 'application/json' });
            response.end(
                JSON.stringify({
                    error: { message: 'unexpected request', code: 400 },
                }),
            );

            return;
        }

        const chunk = (choices, extra = {}) =>
            response.write(
                `data: ${JSON.stringify({ id: 'chatcmpl-mock', object: 'chat.completion.chunk', model: body.model, choices, ...extra })}\n\n`,
            );

        response.writeHead(200, { 'Content-Type': 'text/event-stream' });
        chunk([
            {
                index: 0,
                delta: { role: 'assistant', reasoning_content: 'Kısa düşün.' },
            },
        ]);

        for (const content of COMPATIBLE_ANSWER) {
            await sleep(40);
            chunk([{ index: 0, delta: { content }, finish_reason: null }]);
        }

        chunk([{ index: 0, delta: {}, finish_reason: 'stop' }]);
        chunk([], {
            usage: {
                prompt_tokens: 42,
                completion_tokens: 12,
                total_tokens: 54,
            },
        });
        response.end('data: [DONE]\n\n');

        return;
    }

    response.writeHead(404);
    response.end();
});

server.listen(port, '127.0.0.1', () => console.log(`mock provider on ${port}`));
