import { createInterface } from 'node:readline';
import { spawn } from 'node:child_process';
import { resolve } from 'node:path';

const rootDir = resolve(import.meta.dirname, '..');

const services = [
    {
        name: 'accounting-api',
        cwd: 'apps/accounting',
        command: 'php',
        args: ['-S', '0.0.0.0:8000', '-t', 'public'],
    },
    {
        name: 'accounting-web',
        cwd: 'apps/accounting-web',
        command: 'bun',
        args: ['run', 'dev'],
    },
] as const;

const children: Array<{ name: string; process: ReturnType<typeof spawn> }> = [];
let stopping = false;

function forwardOutput(
    child: ReturnType<typeof spawn>,
    name: string,
    stream: 'stdout' | 'stderr',
): void {
    const output = child[stream];

    if (!output) {
        return;
    }

    const lines = createInterface({ input: output });
    lines.on('line', (line) => {
        const write = stream === 'stderr' ? console.error : console.log;
        write(`[${name}] ${line}`);
    });
}

function waitForExit(child: ReturnType<typeof spawn>): Promise<void> {
    if (child.exitCode !== null || child.signalCode !== null) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        child.once('exit', () => resolve());
    });
}

async function stopAll(exitCode: number): Promise<void> {
    if (stopping) {
        return;
    }

    stopping = true;
    console.log('\nStopping development services...');

    for (const { process: child } of children) {
        if (child.exitCode === null && child.signalCode === null) {
            child.kill('SIGTERM');
        }
    }

    await Promise.race([
        Promise.all(children.map(({ process: child }) => waitForExit(child))),
        new Promise<void>((resolve) => setTimeout(resolve, 2000)),
    ]);

    for (const { process: child } of children) {
        if (child.exitCode === null && child.signalCode === null) {
            child.kill('SIGKILL');
        }
    }

    process.exit(exitCode);
}

function startService(service: (typeof services)[number]): void {
    if (stopping) {
        return;
    }

    const child = spawn(service.command, service.args, {
        cwd: resolve(rootDir, service.cwd),
        env: process.env,
        stdio: ['inherit', 'pipe', 'pipe'],
    });

    children.push({ name: service.name, process: child });
    forwardOutput(child, service.name, 'stdout');
    forwardOutput(child, service.name, 'stderr');

    child.once('error', (error) => {
        if (!stopping) {
            console.error(`[${service.name}] failed to start: ${error.message}`);
            void stopAll(1);
        }
    });

    child.once('exit', (code, signal) => {
        if (!stopping) {
            const reason = signal ? `signal ${signal}` : `exit code ${code ?? 1}`;
            console.error(`[${service.name}] stopped unexpectedly (${reason}).`);
            let exitCode = 1;

            if (signal === 'SIGINT' || signal === 'SIGTERM') {
                exitCode = 0;
            } else if (code && code > 0) {
                exitCode = code;
            }

            void stopAll(exitCode);
        }
    });
}

process.once('SIGINT', () => void stopAll(0));
process.once('SIGTERM', () => void stopAll(0));

console.log('Starting Akunta development services...');
console.log('Press Ctrl+C to stop all services.\n');

for (const service of services) {
    startService(service);
}

await new Promise<void>(() => undefined);
