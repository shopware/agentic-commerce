import { DOMParser, onWarningStopParsing } from '@xmldom/xmldom';
import type { Element } from '@xmldom/xmldom';

export const GOOGLE_MERCHANT_NAMESPACE = 'http://base.google.com/ns/1.0';

/** One feed item; a field repeated in the item (Google's `g:additional_image_link`) becomes a list. */
export type FeedRow = Record<string, string | string[]>;

export function parseJsonlFeed(body: string): FeedRow[] {
    return body
        .split('\n')
        .map((line, index) => ({ line, lineNumber: index + 1 }))
        .filter(({ line }) => line.trim() !== '')
        .map(({ line, lineNumber }) => {
            try {
                return JSON.parse(line) as FeedRow;
            }
            catch (error) {
                throw new Error(`JSONL line ${lineNumber} is not JSON.`, { cause: error });
            }
        });
}

export function parseGoogleMerchantFeed(body: string): FeedRow[] {
    const document = new DOMParser({ onError: onWarningStopParsing }).parseFromString(body, 'text/xml');
    const rss = document.documentElement;

    if (rss?.nodeName !== 'rss' || rss.getAttribute('version') !== '2.0') {
        throw new Error(`Expected an RSS 2.0 document, got <${rss?.nodeName ?? 'nothing'}>.`);
    }

    return Array.from(rss.getElementsByTagName('item')).map((item) => {
        const row: FeedRow = {};

        for (const field of Array.from(item.childNodes).filter((node): node is Element => node.nodeType === node.ELEMENT_NODE)) {
            const name = field.namespaceURI === GOOGLE_MERCHANT_NAMESPACE ? `g:${field.localName}` : field.nodeName;
            const value = field.textContent ?? '';
            const previous = row[name];

            row[name] = previous === undefined ? value : [...(Array.isArray(previous) ? previous : [previous]), value];
        }

        return row;
    });
}
