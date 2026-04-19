<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Application Tailor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app">
        <header>
            <h1>Application <span class="accent">Tailor</span></h1>
            <p class="subtitle">Paste your resume or upload a PDF/Word file, add a job description, and get a tailored version instantly.</p>
        </header>

        <main>
            <div class="inputs-grid">
                <div class="input-group">
                    <label>Resume</label>
                    <div class="tab-bar">
                        <button class="tab active" data-tab="paste">Paste Text</button>
                        <button class="tab" data-tab="upload">Upload File</button>
                    </div>
                    <div id="tab-paste" class="tab-content">
                        <textarea id="resume" placeholder="Paste your full resume here..."></textarea>
                    </div>
                    <div id="tab-upload" class="tab-content hidden">
                        <div class="upload-zone" id="uploadZone">
                            <input type="file" id="resumeFile" accept=".pdf,.doc,.docx" class="file-input" />
                            <div class="upload-icon">📄</div>
                            <p class="upload-label">Click to browse or drag & drop</p>
                            <p class="upload-hint">PDF, DOC, DOCX — max 5MB</p>
                            <p id="fileName" class="file-name hidden"></p>
                        </div>
                    </div>
                </div>

                <div class="input-group">
                    <label for="jobDesc">Job Description</label>
                    <textarea id="jobDesc" placeholder="Paste the job description here..."></textarea>
                </div>
            </div>

            <div class="submit-row">
                <button id="submitBtn">Tailor My Resume</button>
            </div>

            <div id="resultSection" class="result-section hidden">
                <div class="result-header">
                    <h2>Tailored Result</h2>
                    <div class="result-actions">
                        <button id="copyBtn">Copy</button>
                        <button id="previewBtn">👁 Preview</button>
                        <button id="downloadPdfBtn">⬇ PDF</button>
                        <button id="downloadWordBtn">⬇ Word</button>
                    </div>
                </div>

                {{-- Style picker --}}
                <div class="style-picker">
                    <span class="style-label">Resume Style:</span>
                    <label class="style-option">
                        <input type="radio" name="resumeStyle" value="classic" checked />
                        <span class="style-swatch style-classic">Classic</span>
                    </label>
                    <label class="style-option">
                        <input type="radio" name="resumeStyle" value="modern" />
                        <span class="style-swatch style-modern">Modern</span>
                    </label>
                    <label class="style-option">
                        <input type="radio" name="resumeStyle" value="minimal" />
                        <span class="style-swatch style-minimal">Minimal</span>
                    </label>
                </div>

                <div id="resultContent" class="result-content"></div>
            </div>

            <div id="errorBox" class="error-box hidden"></div>
        </main>
    </div>

    {{-- Preview Modal --}}
    <div id="previewModal" class="modal hidden">
        <div class="modal-backdrop" id="modalBackdrop"></div>
        <div class="modal-box">
            <div class="modal-header">
                <span class="modal-title">Resume Preview</span>
                <button class="modal-close" id="modalClose">✕</button>
            </div>
            <div class="modal-body">
                <iframe id="previewFrame" class="preview-frame"></iframe>
            </div>
        </div>
    </div>
</body>
</html>
