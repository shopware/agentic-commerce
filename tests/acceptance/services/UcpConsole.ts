import { spawnSync } from 'node:child_process';
import { resolveShopwareDir } from './pluginSource';

const ALLOWED_COMMANDS = /^ucp:signing-keys:(generate|list|show-public|retire|delete)$/;
const DEFAULT_COMMAND = ['docker', 'compose', 'exec', '-T', 'web', 'php', 'bin/console'];
const DEFAULT_TIMEOUT_MS = 60_000;

export interface ConsoleResult {
    argv: string[]
    status: number | null
    stdout: string
    stderr: string
}

export interface SigningKeySummary {
    kid: string
    algorithm?: string
    status?: string
    [field: string]: unknown
}

export class ConsoleUnavailableError extends Error {
}

/**
 * Runs the plugin's `ucp:signing-keys:*` commands, the only signing-key management surface,
 * through the lane's `bin/console`.
 *
 * The command prefix comes from `UCP_CONSOLE` (whitespace-separated), default
 * `docker compose exec -T web php bin/console`, executed in the Shopware project directory.
 * Nothing else is allowed through here.
 */
export class UcpConsole {
    private availability: boolean | null = null;
    private resolvedShopwareDir: string | null = null;

    /**
     * @param shopwareDir resolved on first use, so a runner that reaches the shop only over HTTP
     *                    can use every fixture that never runs a command
     */
    constructor(
        private readonly shopwareDir: () => string,
        private readonly commandPrefix: string[],
        private readonly timeoutMs = DEFAULT_TIMEOUT_MS,
    ) {
    }

    static fromEnvironment(): UcpConsole {
        const prefix = (process.env.UCP_CONSOLE ?? '').trim();

        return new UcpConsole(
            resolveShopwareDir,
            prefix === '' ? DEFAULT_COMMAND : prefix.split(/\s+/),
            Number(process.env.UCP_CONSOLE_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS),
        );
    }

    describe(): string {
        let directory: string;
        try {
            directory = this.projectDir();
        }
        catch {
            directory = 'no Shopware project found';
        }

        return `${this.commandPrefix.join(' ')} (in ${directory})`;
    }

    /**
     * Whether the configured prefix reaches the lane's `bin/console`. Probes with the real
     * command, since a binary that exists can still target a wrong or stopped container.
     * Cached per instance.
     */
    isAvailable(): boolean {
        if (this.availability === null) {
            try {
                this.availability = this.spawn('ucp:signing-keys:list', ['--help']).status === 0;
            }
            catch {
                this.availability = false;
            }
        }

        return this.availability;
    }

    private projectDir(): string {
        this.resolvedShopwareDir ??= this.shopwareDir();

        return this.resolvedShopwareDir;
    }

    private spawn(command: string, args: string[]) {
        const [executable, ...prefixArgs] = this.commandPrefix;
        const argv = [executable, ...prefixArgs, command, ...args, '--no-interaction'];
        const consoleProcess = spawnSync(executable, argv.slice(1), {
            cwd: this.projectDir(),
            encoding: 'utf8',
            timeout: this.timeoutMs,
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        return { argv, ...consoleProcess };
    }

    run(command: string, args: string[] = []): ConsoleResult {
        if (!ALLOWED_COMMANDS.test(command)) {
            throw new Error(`UcpConsole runs ucp:signing-keys:* only, not "${command}".`);
        }
        if (!this.isAvailable()) {
            throw new ConsoleUnavailableError(
                `"${this.commandPrefix[0]}" is not runnable here. Set UCP_CONSOLE to a command that reaches the lane's bin/console (current: ${this.describe()}).`,
            );
        }

        const { argv, ...consoleProcess } = this.spawn(command, args);

        if (consoleProcess.error) {
            throw new Error(`${argv.join(' ')} failed to start: ${consoleProcess.error.message}`);
        }
        if (consoleProcess.status !== 0) {
            throw new Error(`${argv.join(' ')} exited with ${consoleProcess.status}\n${consoleProcess.stderr}${consoleProcess.stdout}`);
        }

        return { argv, status: consoleProcess.status, stdout: consoleProcess.stdout, stderr: consoleProcess.stderr };
    }

    generateSigningKey(salesChannelId: string, kid: string, algorithm = 'ES256'): ConsoleResult {
        return this.run('ucp:signing-keys:generate', [
            `--sales-channel=${salesChannelId}`,
            `--kid=${kid}`,
            `--algorithm=${algorithm}`,
        ]);
    }

    listSigningKeys(salesChannelId: string): SigningKeySummary[] {
        const { stdout } = this.run('ucp:signing-keys:list', [`--sales-channel=${salesChannelId}`]);

        return parseJsonOutput<SigningKeySummary[]>(stdout, 'ucp:signing-keys:list');
    }

    showPublicSigningKeys(salesChannelId: string): unknown {
        const { stdout } = this.run('ucp:signing-keys:show-public', [`--sales-channel=${salesChannelId}`]);

        return parseJsonOutput<unknown>(stdout, 'ucp:signing-keys:show-public');
    }

    retireSigningKey(salesChannelId: string, kid: string): ConsoleResult {
        return this.run('ucp:signing-keys:retire', [`--sales-channel=${salesChannelId}`, `--kid=${kid}`]);
    }

    deleteSigningKey(salesChannelId: string, kid: string): ConsoleResult {
        return this.run('ucp:signing-keys:delete', [`--sales-channel=${salesChannelId}`, `--kid=${kid}`]);
    }
}

/** The commands print JSON after any Symfony deprecation or profiler noise; parse from the first bracket. */
function parseJsonOutput<T>(stdout: string, command: string): T {
    const start = stdout.search(/[[{]/);
    if (start === -1) {
        throw new Error(`${command} printed no JSON:\n${stdout}`);
    }

    return JSON.parse(stdout.slice(start)) as T;
}
