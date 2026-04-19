<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use Barryvdh\DomPDF\Facade\Pdf;

class TailorController extends Controller
{
    // ─── Tailor ───────────────────────────────────────────────────────────────

    public function tailor(Request $request)
    {
        $request->validate([
            'resume'          => 'nullable|string',
            'resume_file'     => 'nullable|file|mimes:pdf,docx,doc|max:5120',
            'job_description' => 'required|string|min:20',
        ]);

        $resumeText = '';

        if ($request->hasFile('resume_file')) {
            $resumeText = $this->extractTextFromFile($request->file('resume_file'));
            if (!$resumeText) {
                return response()->json(['error' => 'Could not extract text from the uploaded file. Please paste your resume as text instead.'], 422);
            }
        } elseif ($request->filled('resume')) {
            $resumeText = $request->input('resume');
        } else {
            return response()->json(['error' => 'Please provide a resume — either paste the text or upload a file.'], 422);
        }

        if (strlen(trim($resumeText)) < 50) {
            return response()->json(['error' => 'The resume text is too short. Please provide a complete resume.'], 422);
        }

        $jobDescription = $request->input('job_description');
        $prompt         = $this->buildPrompt($resumeText, $jobDescription);
        $groqKey        = config('services.groq.key');
        $apiKey         = config('services.gemini.key');

        if ($groqKey) {
            $response = Http::timeout(60)->withHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $groqKey,
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => 4096,
                'temperature' => 0.7,
            ]);

            if ($response->failed()) {
                Log::error('Groq API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['error' => 'AI API request failed: ' . $response->status()], 502);
            }

            $markdown = data_get($response->json(), 'choices.0.message.content', '');
            if (!$markdown) {
                return response()->json(['error' => 'The AI returned an empty response.'], 502);
            }
            return response()->json(['result' => $markdown]);
        }

        if ($apiKey && $apiKey !== 'your_gemini_api_key_here') {
            $response = Http::timeout(60)->withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key={$apiKey}", [
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 4096],
            ]);

            if ($response->failed()) {
                Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['error' => 'AI API request failed: ' . $response->status()], 502);
            }

            $markdown = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            if (!$markdown) {
                return response()->json(['error' => 'The AI returned an empty response.'], 502);
            }
            return response()->json(['result' => $markdown]);
        }

        $dummy = "## Tailored Resume\n\n"
            . "**Summary:** Results-driven professional with experience aligned to this role.\n\n"
            . "### Experience\n\n"
            . "- Spearheaded cross-functional initiatives delivering measurable outcomes\n"
            . "- Reduced system latency by 40% through targeted refactoring\n"
            . "- Collaborated with stakeholders to ship features ahead of schedule\n\n"
            . "> ⚠️ *No API key set. Add `GROQ_API_KEY` or `GEMINI_API_KEY` to `.env`*";

        return response()->json(['result' => $dummy]);
    }

    // ─── Preview HTML ─────────────────────────────────────────────────────────

    public function preview(Request $request)
    {
        $request->validate([
            'markdown' => 'required|string',
            'style'    => 'nullable|string|in:classic,modern,minimal',
        ]);

        $style = $request->input('style', 'classic');
        $html  = $this->markdownToStyledHtml($request->input('markdown'), $style);

        return response($html)->header('Content-Type', 'text/html');
    }

    // ─── Download PDF ─────────────────────────────────────────────────────────

    public function downloadPdf(Request $request)
    {
        $request->validate([
            'markdown' => 'required|string',
            'style'    => 'nullable|string|in:classic,modern,minimal',
        ]);

        $style    = $request->input('style', 'classic');
        $markdown = $request->input('markdown');

        $nonEmpty    = count(array_filter(explode("\n", $markdown), fn($l) => trim($l) !== ''));
        $bulletCount = substr_count($markdown, "\n- ");

        $fontSize = match (true) {
            $nonEmpty > 70 || $bulletCount > 25 => 7.5,
            $nonEmpty > 50 || $bulletCount > 18 => 8.0,
            $nonEmpty > 35 || $bulletCount > 12 => 8.5,
            $nonEmpty > 20 || $bulletCount > 7  => 9.0,
            default                             => 9.5,
        };

        $html = $this->markdownToStyledHtml($markdown, $style, $fontSize);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'             => 'DejaVu Sans',
                'isHtml5ParserEnabled'    => true,
                'isPhpEnabled'            => false,
                'dpi'                     => 150,
                'defaultMediaType'        => 'print',
                'isFontSubsettingEnabled' => true,
            ]);

        return $pdf->download('tailored-resume.pdf');
    }

    // ─── Download Word ────────────────────────────────────────────────────────

    public function downloadWord(Request $request)
    {
        $request->validate([
            'markdown' => 'required|string',
            'style'    => 'nullable|string|in:classic,modern,minimal',
        ]);

        $markdown = $request->input('markdown');
        $style    = $request->input('style', 'classic');
        $docx     = $this->buildDocx($markdown, $style);

        return response($docx)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="tailored-resume.docx"')
            ->header('Content-Length', strlen($docx));
    }

    // ─── Styles config ────────────────────────────────────────────────────────

    private function getStyleConfig(string $style): array
    {
        return match ($style) {
            'classic' => [
                'bodyColor'     => '#1a1a1a',
                'h1Color'       => '#1a1a2e',
                'h1BorderColor' => '#c0392b',
                'h2Color'       => '#c0392b',
                'accentColor'   => '#c0392b',
                'fontFamily'    => '"DejaVu Sans", Arial, sans-serif',
                'wordFont'      => 'Calibri',
                'wordH1Color'   => '1a1a2e',
                'wordH2Color'   => 'c0392b',
                'wordBullet'    => '•',
            ],
            'modern' => [
                'bodyColor'     => '#1a1a1a',
                'h1Color'       => '#0d3349',
                'h1BorderColor' => '#c9a84c',
                'h2Color'       => '#0d3349',
                'accentColor'   => '#c9a84c',
                'fontFamily'    => '"DejaVu Sans", Arial, sans-serif',
                'wordFont'      => 'Calibri',
                'wordH1Color'   => '0d3349',
                'wordH2Color'   => '0d3349',
                'wordBullet'    => '>',
            ],
            'minimal' => [
                'bodyColor'     => '#000000',
                'h1Color'       => '#000000',
                'h1BorderColor' => '#000000',
                'h2Color'       => '#333333',
                'accentColor'   => '#000000',
                'fontFamily'    => '"DejaVu Sans", Arial, sans-serif',
                'wordFont'      => 'Arial',
                'wordH1Color'   => '000000',
                'wordH2Color'   => '333333',
                'wordBullet'    => '-',
            ],
            default => [
                'bodyColor'     => '#1a1a1a',
                'h1Color'       => '#1a1a2e',
                'h1BorderColor' => '#c0392b',
                'h2Color'       => '#c0392b',
                'accentColor'   => '#c0392b',
                'fontFamily'    => '"DejaVu Sans", Arial, sans-serif',
                'wordFont'      => 'Calibri',
                'wordH1Color'   => '1a1a2e',
                'wordH2Color'   => 'c0392b',
                'wordBullet'    => '•',
            ],
        };
    }

    // ─── Markdown → Styled HTML ───────────────────────────────────────────────

    private function markdownToStyledHtml(string $markdown, string $style = 'classic', float $fontSize = 9.5): string
    {
        $cfg    = $this->getStyleConfig($style);
        $lines  = explode("\n", $markdown);
        $html   = '';
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($inList && !preg_match('/^- /', $trimmed) && $trimmed !== '') {
                $html  .= '</ul>';
                $inList = false;
            }

            if (preg_match('/^## (.+)/', $trimmed, $m)) {
                $html .= '<h1>' . htmlspecialchars($m[1]) . '</h1>';
            } elseif (preg_match('/^### (.+)/', $trimmed, $m)) {
                $html .= '<h2>' . htmlspecialchars($m[1]) . '</h2>';
            } elseif (preg_match('/^# (.+)/', $trimmed, $m)) {
                $html .= '<h1>' . htmlspecialchars($m[1]) . '</h1>';
            } elseif (preg_match('/^- (.+)/', $trimmed, $m)) {
                if (!$inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>' . $this->inlineMarkdown($m[1]) . '</li>';
            } elseif (preg_match('/^> (.+)/', $trimmed, $m)) {
                $html .= '<blockquote>' . htmlspecialchars($m[1]) . '</blockquote>';
            } elseif ($trimmed === '') {
                // skip blank lines
            } else {
                $html .= '<p>' . $this->inlineMarkdown($trimmed) . '</p>';
            }
        }

        if ($inList) $html .= '</ul>';

        $bodyColor     = $cfg['bodyColor'];
        $h1Color       = $cfg['h1Color'];
        $h1BorderColor = $cfg['h1BorderColor'];
        $h2Color       = $cfg['h2Color'];
        $fontFamily    = $cfg['fontFamily'];

        $h1Size   = round($fontSize * 1.55, 1);
        $h2Size   = round($fontSize * 1.05, 1);
        $noteSize = round($fontSize * 0.88, 1);

        $marginV  = round($fontSize * 2.2);
        $h1mt     = round($fontSize * 0.9);
        $h1mb     = round($fontSize * 0.35);
        $h2mt     = round($fontSize * 0.7);
        $h2mb     = round($fontSize * 0.2);
        $pMargin  = round($fontSize * 0.22);
        $ulMargin = round($fontSize * 0.4);
        $liMargin = round($fontSize * 0.15);

        $modernSidebar = $style === 'modern'
            ? 'border-left: 3px solid #c9a84c; padding-left: 10px;'
            : '';

        return "<!DOCTYPE html>
<html>
<head>
<meta charset=\"UTF-8\">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html { width: 210mm; }
  body {
    font-family: {$fontFamily};
    font-size: {$fontSize}pt;
    color: {$bodyColor};
    line-height: 1.4;
    margin: {$marginV}px 36px;
    width: calc(210mm - 72px);
    max-width: calc(210mm - 72px);
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    {$modernSidebar}
  }
  h1 {
    font-size: {$h1Size}pt;
    color: {$h1Color};
    border-bottom: 1.5px solid {$h1BorderColor};
    padding-bottom: 2px;
    margin-top: {$h1mt}px;
    margin-bottom: {$h1mb}px;
    word-break: break-word;
  }
  h2 {
    font-size: {$h2Size}pt;
    color: {$h2Color};
    margin-top: {$h2mt}px;
    margin-bottom: {$h2mb}px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-weight: bold;
    word-break: break-word;
  }
  p  { margin: {$pMargin}px 0; word-break: break-word; }
  ul { margin: {$ulMargin}px 0 {$ulMargin}px 0; padding-left: 14px; width: 100%; }
  li { margin-bottom: {$liMargin}px; word-break: break-word; }
  strong { font-weight: bold; }
  em     { font-style: italic; }
  blockquote {
    border-left: 2px solid {$h1BorderColor};
    padding-left: 6px;
    color: #888;
    font-style: italic;
    font-size: {$noteSize}pt;
    margin: {$pMargin}px 0;
    word-break: break-word;
  }
</style>
</head>
<body>{$html}</body>
</html>";
    }

    // ─── Build valid DOCX from scratch ────────────────────────────────────────

    private function buildDocx(string $markdown, string $style = 'classic'): string
    {
        $cfg = $this->getStyleConfig($style);

        $nonEmpty    = count(array_filter(explode("\n", $markdown), fn($l) => trim($l) !== ''));
        $bulletCount = substr_count($markdown, "\n- ");

        $bodySize = match (true) {
            $nonEmpty > 70 || $bulletCount > 25 => 18,
            $nonEmpty > 50 || $bulletCount > 18 => 20,
            $nonEmpty > 35 || $bulletCount > 12 => 20,
            default                             => 22,
        };

        $h1Size = $bodySize + 14;
        $h2Size = $bodySize + 2;

        $spaceAfter = match (true) {
            $nonEmpty > 70 => '40',
            $nonEmpty > 50 => '60',
            $nonEmpty > 35 => '80',
            default        => '100',
        };

        $h2SpaceBefore = match (true) {
            $nonEmpty > 70 => '80',
            $nonEmpty > 50 => '100',
            default        => '140',
        };

        $marginTop  = '1080';
        $marginSide = '1260';

        $h1Color  = ltrim($cfg['wordH1Color'], '#');
        $h2Color  = ltrim($cfg['wordH2Color'], '#');
        $fontName = htmlspecialchars($cfg['wordFont']);
        $bullet   = htmlspecialchars($cfg['wordBullet']);

        $bodyXml = $this->markdownToOoxml(
            $markdown, $fontName, $bodySize, $h1Size, $h2Size,
            $h1Color, $h2Color, $spaceAfter, $h2SpaceBefore,
            $bullet, $style
        );

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
            xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
            xmlns:o="urn:schemas-microsoft-com:office:office"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
            xmlns:v="urn:schemas-microsoft-com:vml"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
            mc:Ignorable="w14">
  <w:body>
' . $bodyXml . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="' . $marginTop . '" w:right="' . $marginSide . '" w:bottom="' . $marginTop . '" w:left="' . $marginSide . '" w:header="709" w:footer="709" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
            ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/settings.xml"
            ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>';

        $wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"
    Target="settings.xml"/>
</Relationships>';

        $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:compat>
    <w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/>
  </w:compat>
</w:settings>';

        $tmpZip = tempnam(sys_get_temp_dir(), 'docx_') . '.zip';
        $zip    = new \ZipArchive();

        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create zip archive');
        }

        $zip->addFromString('[Content_Types].xml',          $contentTypes);
        $zip->addFromString('_rels/.rels',                  $rels);
        $zip->addFromString('word/document.xml',            $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $wordRels);
        $zip->addFromString('word/settings.xml',            $settings);
        $zip->close();

        $bytes = file_get_contents($tmpZip);
        unlink($tmpZip);

        return $bytes;
    }

    // ─── Markdown → OOXML paragraphs ─────────────────────────────────────────

    private function markdownToOoxml(
        string $markdown,
        string $font,
        int    $bodySize,
        int    $h1Size,
        int    $h2Size,
        string $h1Color,
        string $h2Color,
        string $spaceAfter,
        string $h2SpaceBefore,
        string $bullet,
        string $style
    ): string {
        $xml   = '';
        $lines = explode("\n", $markdown);

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^#{1,2} (.+)/', $trimmed, $m)) {
                $text      = htmlspecialchars(trim($m[1]));
                $borderXml = $style !== 'minimal'
                    ? '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="' . $h2Color . '"/></w:pBdr>'
                    : '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="000000"/></w:pBdr>';

                $xml .= '
    <w:p>
      <w:pPr>
        <w:spacing w:before="0" w:after="' . $spaceAfter . '"/>
        ' . $borderXml . '
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:b/>
          <w:sz w:val="' . $h1Size . '"/>
          <w:szCs w:val="' . $h1Size . '"/>
          <w:color w:val="' . $h1Color . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . $text . '</w:t>
      </w:r>
    </w:p>';

            } elseif (preg_match('/^### (.+)/', $trimmed, $m)) {
                $text    = htmlspecialchars(trim($m[1]));
                $allCaps = $style !== 'minimal' ? '<w:caps/>' : '';

                $xml .= '
    <w:p>
      <w:pPr>
        <w:spacing w:before="' . $h2SpaceBefore . '" w:after="' . (int)((int)$spaceAfter * 0.6) . '"/>
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:b/>
          ' . $allCaps . '
          <w:sz w:val="' . $h2Size . '"/>
          <w:szCs w:val="' . $h2Size . '"/>
          <w:color w:val="' . $h2Color . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . $text . '</w:t>
      </w:r>
    </w:p>';

            } elseif (preg_match('/^- (.+)/', $trimmed, $m)) {
                $xml .= '
    <w:p>
      <w:pPr>
        <w:ind w:left="360" w:hanging="360"/>
        <w:spacing w:before="0" w:after="' . (int)((int)$spaceAfter * 0.6) . '"/>
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:sz w:val="' . $bodySize . '"/>
          <w:szCs w:val="' . $bodySize . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . $bullet . '   </w:t>
      </w:r>'
                    . $this->inlineOoxml(trim($m[1]), $font, $bodySize) . '
    </w:p>';

            } elseif (preg_match('/^> (.+)/', $trimmed, $m)) {
                $text = htmlspecialchars(trim($m[1]));
                $xml .= '
    <w:p>
      <w:pPr>
        <w:spacing w:before="0" w:after="' . $spaceAfter . '"/>
        <w:ind w:left="220"/>
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:i/>
          <w:sz w:val="' . max(16, $bodySize - 2) . '"/>
          <w:szCs w:val="' . max(16, $bodySize - 2) . '"/>
          <w:color w:val="888888"/>
        </w:rPr>
        <w:t xml:space="preserve">' . $text . '</w:t>
      </w:r>
    </w:p>';

            } elseif ($trimmed === '') {
                // Skip blank lines

            } else {
                $xml .= '
    <w:p>
      <w:pPr>
        <w:spacing w:before="0" w:after="' . $spaceAfter . '"/>
      </w:pPr>'
                    . $this->inlineOoxml($trimmed, $font, $bodySize) . '
    </w:p>';
            }
        }

        $xml .= '
    <w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr></w:p>';

        return $xml;
    }

    // ─── Inline bold/italic → OOXML runs ─────────────────────────────────────

    private function inlineOoxml(string $text, string $font, int $size): string
    {
        $parts = preg_split('/(\*\*[^*]+\*\*|\*[^*]+\*)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $xml   = '';

        foreach ($parts as $part) {
            if (preg_match('/^\*\*(.+)\*\*$/', $part, $m)) {
                $xml .= '
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:b/>
          <w:sz w:val="' . $size . '"/>
          <w:szCs w:val="' . $size . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . htmlspecialchars($m[1]) . '</w:t>
      </w:r>';
            } elseif (preg_match('/^\*(.+)\*$/', $part, $m)) {
                $xml .= '
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:i/>
          <w:sz w:val="' . $size . '"/>
          <w:szCs w:val="' . $size . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . htmlspecialchars($m[1]) . '</w:t>
      </w:r>';
            } elseif ($part !== '') {
                $xml .= '
      <w:r>
        <w:rPr>
          <w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>
          <w:sz w:val="' . $size . '"/>
          <w:szCs w:val="' . $size . '"/>
        </w:rPr>
        <w:t xml:space="preserve">' . htmlspecialchars($part) . '</w:t>
      </w:r>';
            }
        }

        return $xml;
    }

    // ─── Inline Markdown → HTML ───────────────────────────────────────────────

    private function inlineMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $text);
        $text = preg_replace('/`(.+?)`/',        '<code>$1</code>',     $text);
        return $text;
    }

    // ─── File extraction ──────────────────────────────────────────────────────

    private function extractTextFromFile($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path      = $file->getPathname();

        try {
            if ($extension === 'pdf') {
                $parser = new PdfParser();
                return $parser->parseFile($path)->getText();
            }
            if (in_array($extension, ['docx', 'doc'])) {
                return $this->extractWordText($path);
            }
        } catch (\Exception $e) {
            Log::error('File extraction error', ['error' => $e->getMessage()]);
        }
        return '';
    }

    private function extractWordText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $lines   = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $lines[] = $this->extractElementText($element);
            }
        }
        return trim(implode("\n", array_filter($lines)));
    }

    private function extractElementText(object $element): string
    {
        // Use string checks instead of instanceof to avoid needing imports
        $class = get_class($element);

        if (str_ends_with($class, 'Text')) {
            return method_exists($element, 'getText') ? $element->getText() : '';
        }
        if (str_ends_with($class, 'ListItem')) {
            return method_exists($element, 'getTextObject')
                ? '- ' . $element->getTextObject()->getText()
                : '';
        }
        if (str_ends_with($class, 'TextRun')) {
            $parts = [];
            if (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $child) {
                    $childClass = get_class($child);
                    if (str_ends_with($childClass, 'Text') && method_exists($child, 'getText')) {
                        $parts[] = $child->getText();
                    }
                }
            }
            return implode('', $parts);
        }
        if (str_ends_with($class, 'Table')) {
            $parts = [];
            if (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        foreach ($cell->getElements() as $el) {
                            $parts[] = $this->extractElementText($el);
                        }
                    }
                }
            }
            return implode(' ', array_filter($parts));
        }
        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }
            return implode(' ', array_filter($parts));
        }
        return '';
    }

    // ─── Prompt ───────────────────────────────────────────────────────────────

    private function buildPrompt(string $resume, string $jobDescription): string
    {
        return <<<PROMPT
You are an expert technical recruiter and resume writer.

Rewrite the resume bullet points below to mirror the language and keywords in the job description. Rules:
1. Do NOT invent experience or skills not in the original resume.
2. Reframe existing bullets to emphasize the most relevant skills.
3. Match keywords from the job description naturally.
4. Return the full rewritten resume in clean Markdown only — no commentary, no preamble.

--- ORIGINAL RESUME ---
{$resume}

--- JOB DESCRIPTION ---
{$jobDescription}

--- TAILORED RESUME (Markdown) ---
PROMPT;
    }
}
