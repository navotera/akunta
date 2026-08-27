import { describe, expect, it } from 'vitest';
import { markdownToHtml, sanitizeNoteContent } from './note-rich-text.js';

describe('sanitizeNoteContent', () => {
  it('preserves the supported rich-text structure without attributes', () => {
    expect(
      sanitizeNoteContent(
        '<h1 class="ignored">Judul</h1><h2>Bagian</h2><p><strong>Tebal</strong></p>',
      ),
    ).toBe('<h1>Judul</h1><h2>Bagian</h2><p><strong>Tebal</strong></p>');
  });

  it('removes executable and unsupported markup', () => {
    const sanitized = sanitizeNoteContent(
      '<p onclick="alert(1)">Aman</p><script>alert(2)</script><img src=x onerror=alert(3)>',
    );

    expect(sanitized).toBe('<p>Aman</p>alert(2)');
    expect(sanitized).not.toContain('onclick');
    expect(sanitized).not.toContain('<script');
    expect(sanitized).not.toContain('<img');
  });

  it('turns plain-text lines into paragraphs and escapes markup', () => {
    expect(sanitizeNoteContent('Baris pertama\n2 < 3')).toBe('<p>Baris pertama</p><p>2 &lt; 3</p>');
  });
});

describe('markdownToHtml', () => {
  it('converts common Markdown blocks and inline formatting to rich text', () => {
    expect(
      markdownToHtml('# Judul\n\n**Tebal** dan *miring*\n\n- Satu\n- Dua\n\n> Catatan penting'),
    ).toBe(
      '<h1>Judul</h1><p><strong>Tebal</strong> dan <em>miring</em></p><ul><li>Satu</li><li>Dua</li></ul><blockquote><p>Catatan penting</p></blockquote>',
    );
  });

  it('escapes pasted HTML and preserves fenced code as visible code', () => {
    const html = markdownToHtml('<script>alert(1)</script>\n\n```js\nconst a = 1 < 2;\n```');

    expect(html).toBe(
      '&lt;script&gt;alert(1)&lt;/script&gt;<pre><code>const a = 1 &lt; 2;</code></pre>',
    );
    expect(html).not.toContain('<script>');
  });

  it('repairs flattened documentation copied without line breaks', () => {
    const html = markdownToHtml(
      'AI Readiness Dosen adalah tingkat kesiapan, pengetahuan, keterampilan, dan kepercayaan diri seorang tenaga pengajar (dosen) dalam memahami, menggunakan, serta mengintegrasikan teknologi kecerdasan buatan (Artificial Intelligence atau AI) secara produktif, etis, dan bertanggung jawab dalam proses pembelajaran dan riset.Aspek Utama Kesiapan AI bagi DosenLiterasi Fundamental: Pemahaman dasar tentang cara kerja AI dan aplikasi generatif seperti Gemini untuk mendukung tugas akademik.Integrasi Pembelajaran: Kemampuan merancang silabus, bahan ajar, dan metode penilaian yang adaptif terhadap perkembangan teknologi.Etika dan Tanggapan: Kesadaran kritis mengenai batasan AI, privasi data, bias algoritma, serta pencegahan plagiarisme.Efisiensi Riset: Pemanfaatan perangkat AI untuk mempercepat peninjauan literatur, pengelolaan referensi, dan analisis data',
    );

    expect(html).toContain('<h1>AI Readiness Dosen</h1>');
    expect(html).toContain('<h2>Aspek Utama Kesiapan AI bagi Dosen</h2>');
    expect(html).toContain('<ul><li><p><strong>Literasi Fundamental:</strong> Pemahaman dasar');
    expect(html).toContain('<li><p><strong>Efisiensi Riset:</strong> Pemanfaatan perangkat AI');
  });

  it('turns aspect lines copied without bullet markers into a list', () => {
    expect(
      markdownToHtml(
        'Literasi Fundamental: Pemahaman dasar tentang AI.\n\nIntegrasi Pembelajaran: Merancang silabus adaptif.\n\nEtika dan Tanggapan: Menjaga privasi data.\n\nEfisiensi Riset: Mempercepat analisis data.',
      ),
    ).toBe(
      '<ul><li><p><strong>Literasi Fundamental:</strong> Pemahaman dasar tentang AI.</p></li><li><p><strong>Integrasi Pembelajaran:</strong> Merancang silabus adaptif.</p></li><li><p><strong>Etika dan Tanggapan:</strong> Menjaga privasi data.</p></li><li><p><strong>Efisiensi Riset:</strong> Mempercepat analisis data.</p></li></ul>',
    );
  });

  it('converts a GFM Markdown table into a visual rich-text table', () => {
    const html = markdownToHtml(
      '| Aspek | Contohnya |\n| --- | --- |\n| **AI Literacy** | Memahami AI dan LLM |\n| **Critical Evaluation** | Memverifikasi sumber dan hasil |',
    );

    expect(html).toBe(
      '<table><thead><tr><th>Aspek</th><th>Contohnya</th></tr></thead><tbody><tr><td><strong>AI Literacy</strong></td><td>Memahami AI dan LLM</td></tr><tr><td><strong>Critical Evaluation</strong></td><td>Memverifikasi sumber dan hasil</td></tr></tbody></table>',
    );
    expect(sanitizeNoteContent(html)).toBe(html);
  });

  it('keeps normal text normal across mixed GFM formatting', () => {
    const html = markdownToHtml(
      '# Judul\n\nTeks normal dengan **satu bagian tebal**, *miring*, ~~dicoret~~, dan [tautan](https://example.com).\n\n- [x] Selesai\n- [ ] Belum\n\n```ts\nconst ready = true;\n```',
    );

    expect(html).toContain('<p>Teks normal dengan <strong>satu bagian tebal</strong>');
    expect(html.match(/<strong>/g)).toHaveLength(1);
    expect(html).toContain('<em>miring</em>');
    expect(html).toContain('<del>dicoret</del>');
    expect(html).toContain('☑ Selesai');
    expect(html).toContain('☐ Belum');
    expect(html).toContain('<pre><code>const ready = true;</code></pre>');
  });
});
