import './bootstrap';
import { marked } from 'marked';

const API_URL = '/api/tailor';

const submitBtn       = document.getElementById('submitBtn');
const copyBtn         = document.getElementById('copyBtn');
const previewBtn      = document.getElementById('previewBtn');
const resumeTA        = document.getElementById('resume');
const jobDescTA       = document.getElementById('jobDesc');
const resumeFile      = document.getElementById('resumeFile');
const uploadZone      = document.getElementById('uploadZone');
const fileName        = document.getElementById('fileName');
const resultSec       = document.getElementById('resultSection');
const resultDiv       = document.getElementById('resultContent');
const errorBox        = document.getElementById('errorBox');
const tabs            = document.querySelectorAll('.tab');
const downloadPdfBtn  = document.getElementById('downloadPdfBtn');
const downloadWordBtn = document.getElementById('downloadWordBtn');
const previewModal    = document.getElementById('previewModal');
const previewFrame    = document.getElementById('previewFrame');
const modalClose      = document.getElementById('modalClose');
const modalBackdrop   = document.getElementById('modalBackdrop');

let rawMarkdown = '';
let activeTab   = 'paste';

marked.setOptions({ breaks: true, gfm: true });

const getStyle = () =>
    document.querySelector('input[name="resumeStyle"]:checked')?.value ?? 'classic';

// ─── Tab switching ────────────────────────────────────────────────────────────
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeTab = tab.dataset.tab;
        document.getElementById('tab-paste').classList.toggle('hidden', activeTab !== 'paste');
        document.getElementById('tab-upload').classList.toggle('hidden', activeTab !== 'upload');
    });
});

// ─── File input ───────────────────────────────────────────────────────────────
resumeFile?.addEventListener('change', () => {
    if (resumeFile.files[0]) {
        fileName.textContent = '✓ ' + resumeFile.files[0].name;
        fileName.classList.remove('hidden');
    }
});

uploadZone?.addEventListener('dragover',  e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
uploadZone?.addEventListener('dragleave', ()  => uploadZone.classList.remove('drag-over'));
uploadZone?.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) {
        resumeFile.files    = e.dataTransfer.files;
        fileName.textContent = '✓ ' + file.name;
        fileName.classList.remove('hidden');
    }
});
uploadZone?.addEventListener('click', () => resumeFile.click());

// ─── Submit ───────────────────────────────────────────────────────────────────
submitBtn.addEventListener('click', async () => {
    const jobDesc = jobDescTA.value.trim();
    errorBox.classList.add('hidden');

    if (!jobDesc) { showError('Please paste a job description.'); return; }

    const formData = new FormData();
    formData.append('job_description', jobDesc);
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

    if (activeTab === 'upload') {
        if (!resumeFile.files[0]) { showError('Please select a PDF or Word file to upload.'); return; }
        formData.append('resume_file', resumeFile.files[0]);
    } else {
        const resumeText = resumeTA.value.trim();
        if (!resumeText) { showError('Please paste your resume text.'); return; }
        formData.append('resume', resumeText);
    }

    setLoading(true);
    resultSec.classList.add('hidden');
    setDownloadButtons(false);

    try {
        const res  = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: formData,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || data.error || `Server error: ${res.status}`);

        rawMarkdown         = data.result || '';
        resultDiv.innerHTML = marked.parse(rawMarkdown);
        resultSec.classList.remove('hidden');
        setDownloadButtons(true);
        resultSec.scrollIntoView({ behavior: 'smooth', block: 'start' });

    } catch (err) {
        showError(err.message || 'Something went wrong.');
        setDownloadButtons(false);
    } finally {
        setLoading(false);
    }
});

// ─── Copy ─────────────────────────────────────────────────────────────────────
copyBtn.addEventListener('click', async () => {
    if (!rawMarkdown) return;
    try {
        await navigator.clipboard.writeText(rawMarkdown);
        copyBtn.textContent = '✓ Copied!';
        copyBtn.classList.add('copied');
        setTimeout(() => { copyBtn.textContent = 'Copy'; copyBtn.classList.remove('copied'); }, 2000);
    } catch { showError('Clipboard access denied.'); }
});

// ─── Preview ──────────────────────────────────────────────────────────────────
previewBtn?.addEventListener('click', async () => {
    if (!rawMarkdown) return;

    previewBtn.disabled    = true;
    previewBtn.textContent = '⏳ Loading...';

    try {
        const res = await fetch('/api/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ markdown: rawMarkdown, style: getStyle() }),
        });

        if (!res.ok) throw new Error('Preview failed');

        const html            = await res.text();
        previewFrame.srcdoc   = html;
        previewModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

    } catch (err) {
        showError('Preview failed: ' + err.message);
    } finally {
        previewBtn.disabled    = false;
        previewBtn.textContent = '👁 Preview';
    }
});

// Re-load preview when style changes while modal is open
document.querySelectorAll('input[name="resumeStyle"]').forEach(radio => {
    radio.addEventListener('change', () => {
        if (!previewModal.classList.contains('hidden') && rawMarkdown) {
            previewBtn.click();
        }
    });
});

// Close modal
modalClose?.addEventListener('click',   closeModal);
modalBackdrop?.addEventListener('click', closeModal);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function closeModal() {
    previewModal.classList.add('hidden');
    document.body.style.overflow = '';
    previewFrame.srcdoc = '';
}

// ─── Downloads ────────────────────────────────────────────────────────────────
downloadPdfBtn?.addEventListener('click',  () => downloadFile('pdf'));
downloadWordBtn?.addEventListener('click', () => downloadFile('word'));

async function downloadFile(type) {
    if (!rawMarkdown) return;

    const btn      = type === 'pdf' ? downloadPdfBtn : downloadWordBtn;
    const original = btn.textContent;
    btn.disabled    = true;
    btn.textContent = '⏳ Generating...';

    try {
        const res = await fetch(`/api/download/${type}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': type === 'pdf'
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ markdown: rawMarkdown, style: getStyle() }),
        });

        if (!res.ok) {
            const errData = await res.json().catch(() => ({}));
            throw new Error(errData.error || `Server error: ${res.status}`);
        }

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = type === 'pdf' ? 'tailored-resume.pdf' : 'tailored-resume.docx';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);

        btn.textContent = type === 'pdf' ? '✓ PDF Downloaded' : '✓ Word Downloaded';
        setTimeout(() => { btn.textContent = original; }, 2500);

    } catch (err) {
        showError('Download failed: ' + err.message);
        btn.textContent = original;
    } finally {
        btn.disabled = false;
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').content;
}
function setLoading(on) {
    submitBtn.disabled    = on;
    submitBtn.textContent = on ? '⏳ Generating...' : 'Tailor My Resume';
}
function setDownloadButtons(enabled) {
    [downloadPdfBtn, downloadWordBtn, previewBtn].forEach(b => { if (b) b.disabled = !enabled; });
}
function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('hidden');
}
