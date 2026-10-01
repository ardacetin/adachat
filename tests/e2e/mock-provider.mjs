/**
 * A stand-in for the OpenAI Responses API and for an OpenAI-compatible
 * Chat Completions server (Ollama, vLLM…), used by the Playwright tests.
 * Ada talks to it exactly as to the real API (the provider's base URL points
 * here), so no test-only code exists in the application.
 *
 *   POST /v1/responses/input_tokens  → token count
 *   POST /v1/responses               → SSE stream (slow when the prompt contains "long")
 *   POST /v1/chat/completions        → SSE stream, data-only chunks ending in [DONE];
 *                                       refuses a request with credentials (keyless server)
 *   GET  /v1/models                  → connection check
 */
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

const server = createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', `http://localhost:${port}`);

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
        const long = String(last).includes('long');
        const chunks = long
            ? Array.from({ length: 80 }, (_, i) => `Kelime ${i + 1}. `)
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
