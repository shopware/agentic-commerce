import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const PLUGIN_COMPOSER_NAME = 'shopware/agentic-commerce';

function findUpwards(start: string, marker: string): string | null {
    let dir = path.resolve(start);
    const { root } = path.parse(dir);

    while (dir !== root) {
        if (fs.existsSync(path.join(dir, marker))) {
            return dir;
        }
        dir = path.dirname(dir);
    }

    return null;
}

function isPluginDir(dir: string): boolean {
    const composerJson = path.join(dir, 'composer.json');
    if (!fs.existsSync(composerJson)) {
        return false;
    }

    const composer = JSON.parse(fs.readFileSync(composerJson, 'utf8')) as { name?: string };

    return composer.name === PLUGIN_COMPOSER_NAME;
}

/**
 * The Shopware project the lane serves: `SHOPWARE_DIR`, else the nearest ancestor with `bin/console`.
 */
export function resolveShopwareDir(): string {
    const fromEnv = process.env.SHOPWARE_DIR;
    if (fromEnv) {
        if (!fs.existsSync(path.join(fromEnv, 'bin', 'console'))) {
            throw new Error(`SHOPWARE_DIR=${fromEnv} has no bin/console.`);
        }

        return path.resolve(fromEnv);
    }

    const found = findUpwards(import.meta.dirname, path.join('bin', 'console'));
    if (found === null) {
        throw new Error(
            'Cannot locate the Shopware project: no bin/console above this suite. Set SHOPWARE_DIR to the project the lane serves.',
        );
    }

    return found;
}

/**
 * The plugin checkout whose sources the fixtures read: `PLUGIN_DIR`, else the suite's own parent,
 * else `custom/plugins/agentic-commerce` under the Shopware project.
 */
export function resolvePluginDir(): string {
    const candidates = [
        process.env.PLUGIN_DIR,
        path.resolve(import.meta.dirname, '..', '..', '..'),
    ].filter((candidate): candidate is string => typeof candidate === 'string');

    try {
        candidates.push(path.join(resolveShopwareDir(), 'custom', 'plugins', 'agentic-commerce'));
    }
    catch {
        // No Shopware dir: only the explicit candidates remain.
    }

    const pluginDir = candidates.find(isPluginDir);
    if (pluginDir === undefined) {
        throw new Error(`Cannot locate the ${PLUGIN_COMPOSER_NAME} checkout. Tried: ${candidates.join(', ')}. Set PLUGIN_DIR.`);
    }

    return pluginDir;
}

export function readUcpProtocolVersion(): string {
    const source = fs.readFileSync(path.join(resolvePluginDir(), 'src', 'Ucp', 'UcpProtocol.php'), 'utf8');
    const match = source.match(/const VERSION = '(\d{4}-\d{2}-\d{2})'/);
    if (match === null) {
        throw new Error('UcpProtocol::VERSION not found in src/Ucp/UcpProtocol.php.');
    }

    return match[1];
}

export interface PrivilegeRole {
    privileges: string[]
    dependencies: string[]
}

export type PrivilegeMapping = Map<string, Record<string, PrivilegeRole>>;

interface PrivilegeMappingEntry {
    key: string
    roles: Record<string, PrivilegeRole>
}

declare global {
    var Shopware: unknown;
}

let privilegeMapping: Promise<PrivilegeMapping> | undefined;

/**
 * Evaluates the Administration ACL file with a stub `Shopware` global that records every
 * `addPrivilegeMappingEntry` call, so the privilege sets come from the shipped source.
 *
 * Node evaluates an ES module once per process, so a second import would register nothing;
 * the first result is kept for every later caller in the worker.
 */
export function readAdminPrivilegeMapping(): Promise<PrivilegeMapping> {
    privilegeMapping ??= evaluateAdminPrivilegeMapping();

    return privilegeMapping;
}

async function evaluateAdminPrivilegeMapping(): Promise<PrivilegeMapping> {
    const aclFile = path.join(
        resolvePluginDir(),
        'src', 'Resources', 'app', 'administration', 'src', 'extension', 'sw-sales-channel', 'acl', 'index.js',
    );
    const mapping: PrivilegeMapping = new Map();

    globalThis.Shopware = {
        Service: () => ({
            addPrivilegeMappingEntry: (entry: PrivilegeMappingEntry) => {
                mapping.set(entry.key, { ...mapping.get(entry.key), ...entry.roles });
            },
        }),
    };

    try {
        await import(pathToFileURL(aclFile).href);
    }
    finally {
        globalThis.Shopware = undefined;
    }

    if (mapping.size === 0) {
        throw new Error(`${aclFile} registered no privilege mapping.`);
    }

    return mapping;
}

export interface ResolvedRole {
    /** Role keys, e.g. `ucp.editor` and `ucp.viewer`, as `acl.can()` in the Administration checks them. */
    keys: string[]
    /** DAL privileges, dependencies included. */
    privileges: string[]
}

export function resolveRole(mapping: PrivilegeMapping, roleKey: string, seen = new Set<string>()): ResolvedRole {
    if (seen.has(roleKey)) {
        return { keys: [], privileges: [] };
    }
    seen.add(roleKey);

    const [key, role] = roleKey.split('.');
    const definition = mapping.get(key)?.[role];
    if (definition === undefined) {
        throw new Error(`Role ${roleKey} is not in the privilege mapping (known: ${[...mapping.keys()].join(', ')}).`);
    }

    const keys = [roleKey];
    const privileges = [...definition.privileges];
    for (const dependency of definition.dependencies) {
        const resolved = resolveRole(mapping, dependency, seen);
        keys.push(...resolved.keys);
        privileges.push(...resolved.privileges);
    }

    return { keys: [...new Set(keys)], privileges: [...new Set(privileges)] };
}
