import fs from 'node:fs';
import path from 'node:path';
import { resolveShopwareDir } from './pluginSource';

export class ShopwareLog {
    private readonly logDir = path.join(resolveShopwareDir(), 'var', 'log');

    private readonly lengthByLogFile = new Map<string, number>();

    private constructor() {
    }

    static mark(): ShopwareLog {
        const shopwareLog = new ShopwareLog();
        for (const logFile of shopwareLog.logFiles()) {
            shopwareLog.lengthByLogFile.set(logFile, fs.statSync(logFile).size);
        }

        return shopwareLog;
    }

    writtenSinceMark(): string {
        return this.logFiles()
            .map(logFile => this.readAppendedBytes(logFile, this.lengthByLogFile.get(logFile) ?? 0).toString('utf8'))
            .join('');
    }

    /** A lane's dev log grows past what `readFileSync` can load, so only the appended part is read. */
    private readAppendedBytes(logFile: string, markedLength: number): Buffer {
        const appendedLength = Math.max(fs.statSync(logFile).size - markedLength, 0);
        const appendedBytes = Buffer.alloc(appendedLength);
        const fileDescriptor = fs.openSync(logFile, 'r');
        try {
            fs.readSync(fileDescriptor, appendedBytes, 0, appendedLength, markedLength);
        }
        finally {
            fs.closeSync(fileDescriptor);
        }

        return appendedBytes;
    }

    private logFiles(): string[] {
        return fs.readdirSync(this.logDir)
            .filter(fileName => fileName.endsWith('.log'))
            .map(fileName => path.join(this.logDir, fileName));
    }
}
