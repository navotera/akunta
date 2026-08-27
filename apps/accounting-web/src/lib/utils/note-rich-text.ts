import { Marked } from 'marked';

const allowedTags = new Set([
  'p',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'strong',
  'em',
  'u',
  's',
  'del',
  'ul',
  'ol',
  'li',
  'blockquote',
  'hr',
  'br',
  'pre',
  'code',
  'a',
  'table',
  'thead',
  'tbody',
  'tr',
  'th',
  'td',
  'img',
]);
const voidTags = new Set(['hr', 'br', 'img']);

function escapeAttribute(value: string): string {
  return escapeText(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
}

function escapeText(text: string): string {
  return text.replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

const markdownParser = new Marked({ breaks: false, gfm: true });
markdownParser.use({
  renderer: {
    html({ text }) {
      return escapeText(text);
    },
  },
});

export function sanitizeNoteContent(content: string): string {
  const trimmed = content.trim();
  if (!trimmed) return '<p class="empty-note">Belum ada konten catatan.</p>';

  if (!/<\/?[a-z][^>]*>/i.test(trimmed)) {
    return trimmed
      .split(/\r?\n/)
      .map((line) => `<p>${escapeText(line) || '<br>'}</p>`)
      .join('');
  }

  const tagPattern = /<\/?([a-z0-9]+)(?:\s[^>]*)?>/gi;
  let result = '';
  let cursor = 0;
  let match: RegExpExecArray | null;

  while ((match = tagPattern.exec(trimmed)) !== null) {
    result += escapeText(trimmed.slice(cursor, match.index));
    const tag = match[1].toLowerCase();

    if (tag === 'input' && /\btype\s*=\s*["']?checkbox/i.test(match[0])) {
      result += /\bchecked\b/i.test(match[0]) ? '☑' : '☐';
    } else if (allowedTags.has(tag)) {
      const closing = match[0].startsWith('</');
      if (tag === 'a' && !closing) {
        const href = match[0].match(/\bhref\s*=\s*["']([^"']+)["']/i)?.[1] ?? '';
        if (/^(https?:\/\/|mailto:)/i.test(href)) {
          result += `<a href="${escapeAttribute(href)}" target="_blank" rel="noreferrer">`;
        } else {
          result += '<a>';
        }
      } else if (tag === 'img' && !closing) {
        const src = match[0].match(/\bsrc\s*=\s*["']([^"']+)["']/i)?.[1] ?? '';
        const alt = match[0].match(/\balt\s*=\s*["']([^"']*)["']/i)?.[1] ?? '';
        result += /^https?:\/\//i.test(src)
          ? `<img src="${escapeAttribute(src)}" alt="${escapeAttribute(alt)}" loading="lazy">`
          : escapeText(alt);
      } else {
        result += voidTags.has(tag) ? `<${tag}>` : `<${closing ? '/' : ''}${tag}>`;
      }
    }

    cursor = match.index + match[0].length;
  }

  result += escapeText(trimmed.slice(cursor));
  return result;
}

/** Convert common Markdown pasted as plain text into editor-ready HTML. */
export function markdownToHtml(markdown: string): string {
  let source = markdown.replaceAll('\r\n', '\n').replace(/^[\u200B-\u200F\uFEFF]/, '');

  // Some PDF/web sources flatten all visual line breaks when copied. Recover
  // the common documentation shape before parsing the Markdown blocks.
  if (!source.includes('\n')) {
    source = source
      .replace(/^(.{3,100}?)\s+(adalah\s+(?=tingkat|kemampuan|proses|cara|sistem))/i, '# $1\n\n$2')
      .replace(/\s*(Aspek Utama\s+[^.]{3,100}?)(?=Literasi Fundamental:)/i, '\n\n## $1\n\n')
      .replace(
        /\s*(Literasi Fundamental:|Integrasi Pembelajaran:|Etika dan Tanggapan:|Efisiensi Riset:)/gi,
        '\n\n$1',
      );
  }

  // Copying from a rendered list often keeps the line breaks but drops the
  // bullet markers. These documented aspect labels are still unambiguous.
  source = source.replace(
    /^(Literasi Fundamental|Integrasi Pembelajaran|Etika dan Tanggapan|Efisiensi Riset):\s*(.+)$/gim,
    '- **$1:** $2',
  );

  const rendered = markdownParser.parse(source, { async: false });
  return sanitizeNoteContent(rendered)
    .replace(/>[\t\r\n]+</g, '><')
    .replace(/\n<\/code>/g, '</code>')
    .trim();
}
